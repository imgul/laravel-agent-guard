<?php

declare(strict_types=1);

/**
 * loop-budget-bench — micro-benchmark for the two hot paths of
 * laravel-agent-guard: loop detection and budget reservation.
 *
 * Run with:
 *   php benchmarks/loop-budget-bench.php
 *   php benchmarks/loop-budget-bench.php --runs=200000
 *
 * The budget path is measured against the in-memory ledger by default (no
 * Redis required). Pass --driver=redis to measure against a live Redis
 * ledger; the script will skip gracefully if the connection is unavailable.
 *
 * Output is plain text: operations/sec and microseconds-per-op, so the
 * numbers can be pasted into docs/benchmarks.md.
 */

require __DIR__.'/../vendor/autoload.php';

use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Budget\Drivers\RedisLedger;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Loop\LoopDetector;
use Imgul\AgentGuard\Loop\ToolCall;
use Imgul\AgentGuard\Support\Similarity\SimHash;
use Imgul\AgentGuard\Support\TextUsage;
use Imgul\AgentGuard\Testing\InMemoryLedger;

$runs = 100000;
$driver = 'memory';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--runs=')) {
        $runs = max(1, (int) substr($arg, 7));
    } elseif (str_starts_with($arg, '--driver=')) {
        $driver = substr($arg, 9);
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This benchmark must run from the CLI.\n");
    exit(1);
}

/**
 * Measure a callable over N iterations and report ops/sec and µs/op.
 */
function bench(string $name, int $runs, callable $fn): void
{
    // Warm up (JIT / caches).
    for ($i = 0; $i < min(1000, $runs); $i++) {
        $fn();
    }

    $start = hrtime(true);

    for ($i = 0; $i < $runs; $i++) {
        $fn();
    }

    $elapsedNs = hrtime(true) - $start;
    $elapsedUs = $elapsedNs / 1000.0;
    $opsPerSec = $runs / ($elapsedUs / 1_000_000.0);
    $usPerOp = $elapsedUs / $runs;

    printf(
        "%-28s %10d ops  %12.0f ops/sec  %8.2f µs/op\n",
        $name,
        $runs,
        $opsPerSec,
        $usPerOp
    );
}

echo "laravel-agent-guard benchmark  (runs={$runs}, driver={$driver})\n";
echo str_repeat('-', 70), "\n";

// ---------------------------------------------------------------------------
// 1. Loop detection — exact-repeat path, the most common trip.
// ---------------------------------------------------------------------------
$detector = new LoopDetector(
    windowSize: 50,
    maxRepeats: 3,
    maxStepGrowthRatio: 2.5,
    frequencyThreshold: 3,
    similarity: new SimHash,
    similarityThreshold: 0.85,
    exemptTools: [],
    dispatcher: null, // no events — measuring the hot path only.
);

$call = new ToolCall(tool: 'search', args: ['q' => 'benchmark']);

bench('loop-detect (exact-repeat)', $runs, function () use ($detector, $call): void {
    $detector->check($call, 256);
});

// ---------------------------------------------------------------------------
// 2. Budget reservation — the per-step ledger write.
// ---------------------------------------------------------------------------
$ledger = resolveLedger($driver);

$guard = new BudgetGuard(ledger: $ledger, enforce: false, dispatcher: null);
$guard->cap(BudgetScope::Tenant, softCap: 15.00, hardCap: 20.00);

$keyParts = ['tenant-bench', date('Y-m-d')];

bench('budget-reserve (in-memory)', $runs, function () use ($guard, $keyParts): void {
    $guard->reserve(BudgetScope::Tenant, $keyParts, 0.05);
});

// ---------------------------------------------------------------------------
// 3. Budget reconciliation — the post-step ledger write.
// ---------------------------------------------------------------------------
$usage = TextUsage::fromArray(['input_tokens' => 1200, 'output_tokens' => 300]);

bench('budget-reconcile (in-memory)', $runs, function () use ($guard, $keyParts, $usage): void {
    $guard->reconcile(BudgetScope::Tenant, $keyParts, 0.05, $usage, 12.50);
});

echo str_repeat('-', 70), "\n";
echo "Notes:\n";
echo "  - Loop detection measures the detector pipeline without event dispatch.\n";
echo "  - Budget paths use the in-memory ledger for portability; --driver=redis\n";
echo "    measures the atomic Lua reservation path against a live connection.\n";

/**
 * Resolve the ledger driver, falling back to in-memory when Redis is absent.
 */
function resolveLedger(string $driver): Ledger
{
    if ($driver === 'redis') {
        try {
            $redis = new Redis;
            $redis->connect('127.0.0.1', 6379);

            return new RedisLedger($redis);
        } catch (Throwable $e) {
            fwrite(STDERR, "Redis unavailable ({$e->getMessage()}); falling back to in-memory.\n");
        }
    }

    return new InMemoryLedger;
}
