<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Budget\Drivers\RedisLedger;
use Imgul\AgentGuard\Budget\Ledger;

/**
 * Hard gate (PLAN Phase 2): the atomic Redis Lua ledger must be race-safe.
 *
 * 10 parallel workers each try to reserve $1 against a $5 daily hard cap.
 * The atomic Lua reservation guarantees exactly 5 succeed and the total
 * spend never exceeds $5 — the classic TOCTOU Horizon race is solved.
 *
 * Skipped when no Redis server is reachable (e.g. local runs without the
 * CI Redis service); the Lua script's atomicity is proven in CI.
 */
function redisAvailable(): bool
{
    try {
        Redis::set('agent-guard:test:ping', '1');
        Redis::del('agent-guard:test:ping');

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('holds a $5 daily cap under 10 concurrent $1 reservations with zero overspend', function (): void {
    if (! redisAvailable()) {
        // @phpstan-ignore-next-line
        skip('Redis server not reachable; concurrency gate runs in CI.');
    }

    /** @var \Redis $client */
    $client = Redis::connection()->client();
    $ledger = new RedisLedger($client);

    $key = 'agent-guard:budget:tenant:concurrency-test:2026-11-24';
    Redis::del($key);

    $hardCap = 5.0;
    $cost = 1.0;

    // Fire 10 concurrent reservations. Each is a single atomic Lua round-trip,
    // so the server serializes them — no two workers can pass the check and
    // each spend past the cap. The workers run as separate PHP processes (a
    // standalone worker script spawned with proc_open) that must connect to
    // the *same* Redis database and use the *same* key prefix the parent
    // client uses, so capture the parent client's host/port/database/prefix
    // and pass them as arguments. Without the prefix the raw worker client
    // would read/write a different physical key than the parent's RedisLedger.
    $luaPath = __DIR__.'/../../src/Budget/Lua/reserve.lua';
    $host = $client->getHost();
    $port = $client->getPort();
    $db = $client->getDbNum();
    /** @var string|false $prefixOption */
    $prefixOption = $client->getOption(\Redis::OPT_PREFIX);
    $prefix = $prefixOption === false ? '' : $prefixOption;

    $results = [];
    $stderrs = [];
    $workers = [];
    $stdoutPipes = [];
    $stderrPipes = [];
    $workerScript = __DIR__.'/../concurrency-worker.php';

    for ($i = 0; $i < 10; $i++) {
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($workerScript),
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $db),
            escapeshellarg($prefix),
            escapeshellarg($key),
            escapeshellarg($luaPath),
        ]);

        $procPipes = [];
        $workers[$i] = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $procPipes,
        );
        $stdoutPipes[$i] = $procPipes[1];
        $stderrPipes[$i] = $procPipes[2];
    }

    foreach ($workers as $i => $proc) {
        $output = stream_get_contents($stdoutPipes[$i]);
        $stderr = stream_get_contents($stderrPipes[$i]);
        $results[] = (int) trim((string) $output);
        $stderrs[] = trim((string) $stderr);

        if (is_resource($proc)) {
            proc_close($proc);
        }
    }

    // Exactly 5 reservations must succeed (return 1), 5 denied (return 0).
    $allowed = count(array_filter($results, fn (int $code): bool => $code === 1));
    $denied = count(array_filter($results, fn (int $code): bool => $code === 0));

    // If the workers did not produce the expected 5 allowed, surface their
    // stderr so the failure is diagnosable (worker script errors, missing
    // redis extension, wrong DB, etc.).
    if ($allowed !== 5 || $denied !== 5) {
        throw new RuntimeException(
            'Concurrency workers produced '.$allowed.' allowed / '.$denied.' denied. '.
            'Worker stderr: '.implode(' | ', $stderrs),
        );
    }

    expect($allowed)->toBe(5)
        ->and($denied)->toBe(5)
        ->and(array_sum($results))->toBe(5); // 5 * 1 = 5

    // The ledger's recorded spend must never exceed the hard cap.
    expect($ledger->spent($key))->toBeLessThanOrEqual($hardCap)
        ->and($ledger->spent($key))->toBe(5.0);

    Redis::del($key);
});

it('signals soft-cap breach via the same atomic call', function (): void {
    if (! redisAvailable()) {
        // @phpstan-ignore-next-line
        skip('Redis server not reachable; runs in CI.');
    }

    /** @var \Redis $client */
    $client = Redis::connection()->client();
    $ledger = new RedisLedger($client);

    $key = 'agent-guard:budget:tenant:softcap-test:2026-11-24';
    Redis::del($key);

    $reservation = new BudgetReservation(
        scope: BudgetScope::Tenant,
        keyParts: ['softcap-test', '2026-11-24'],
        cost: 6.0,
        softCap: 5.0,
        hardCap: 10.0,
        ttlSeconds: 86400,
    );

    $result = $ledger->reserve($reservation);

    expect($result->allowed)->toBeTrue()
        ->and($result->softCapExceeded)->toBeTrue()
        ->and($result->spentAfter)->toBe(6.0);

    Redis::del($key);
});

it('reconciles a reservation back to actual spend', function (): void {
    if (! redisAvailable()) {
        // @phpstan-ignore-next-line
        skip('Redis server not reachable; runs in CI.');
    }

    /** @var \Redis $client */
    $client = Redis::connection()->client();
    $ledger = new RedisLedger($client);

    $key = 'agent-guard:budget:run:reconcile-test';
    Redis::del($key);

    $reservation = new BudgetReservation(
        scope: BudgetScope::Run,
        keyParts: ['reconcile-test'],
        cost: 1.0,
        softCap: 0.0,
        hardCap: 5.0,
        ttlSeconds: 86400,
    );

    $ledger->reserve($reservation);
    expect($ledger->spent($key))->toBe(1.0);

    // Actual spend was $0.40; release $0.60 back to the ledger.
    $excess = $ledger->reconcile($reservation, 0.40);

    expect($excess)->toBe(0.60)
        ->and($ledger->spent($key))->toBe(0.40);

    Redis::del($key);
});
