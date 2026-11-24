<?php

declare(strict_types=1);

use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Budget\Drivers\RedisLedger;
use Imgul\AgentGuard\Budget\Ledger;
use Illuminate\Support\Facades\Redis;

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
        test()->markTestSkipped('Redis server not reachable; concurrency gate runs in CI.');
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
    // each spend past the cap. The workers run as separate PHP processes that
    // must connect to the *same* Redis database the parent reads from, so
    // capture the parent client's host/port/database and inject them.
    $host = $client->getHost();
    $port = $client->getPort();
    $db = $client->getDbNum();

    $results = [];
    $workers = [];
    $stdoutPipes = [];

    for ($i = 0; $i < 10; $i++) {
        $command = sprintf(
            '%s -r %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(
                '$r = new Redis(); $r->connect('.var_export($host, true).', '.var_export($port, true).'); '.
                '$r->select('.var_export($db, true).'); '.
                '$script = file_get_contents('.var_export(__DIR__.'/../../src/Budget/Lua/reserve.lua', true).'); '.
                'echo $r->eval($script, ['.var_export($key, true).', 1.0, 0.0, 5.0, 86400], 1);'
            ),
        );

        $procPipes = [];
        $workers[$i] = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $procPipes,
        );
        $stdoutPipes[$i] = $procPipes[1];
    }

    foreach ($workers as $i => $proc) {
        $output = stream_get_contents($stdoutPipes[$i]);
        $results[] = (int) trim((string) $output);
        proc_close($proc);
    }

    // Exactly 5 reservations must succeed (return 1), 5 denied (return 0).
    $allowed = count(array_filter($results, fn (int $code): bool => $code === 1));
    $denied = count(array_filter($results, fn (int $code): bool => $code === 0));

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
        test()->markTestSkipped('Redis server not reachable; runs in CI.');
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
        test()->markTestSkipped('Redis server not reachable; runs in CI.');
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
