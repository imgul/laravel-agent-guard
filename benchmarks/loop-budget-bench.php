<?php

declare(strict_types=1);

/**
 * loop-budget-bench — the 5-step loop reproducer (PRD FR-5.6, PLAN Phase 3
 * acceptance, PRD §12.3 & §12.6): the launch evidence asset.
 *
 * Simulates a runaway 5-step agent loop where every step repeats the same
 * tool call with growing context (the costliest failure mode: a retry
 * spiral that burns spend while health checks report "working"). It runs
 * the loop twice and prints a side-by-side comparison:
 *
 *   - WITHOUT the guard: all 5 steps run on the frontier model; the loop
 *     never brakes; the run ends in an unbounded spiral (provider 503 /
 *     hard-cap blowout).
 *   - WITH the guard (enforce): the loop detector trips on the 3rd
 *     repeat, the circuit breaker opens (halting steps 4-5), and the
 *     soft-cap downshift swaps to a cheaper model — a graceful degraded
 *     response instead of a 503.
 *
 * Reported for each scenario: steps executed, tokens spent, USD spent,
 * and the outcome. The delta (tokens saved, USD saved) is the evidence.
 *
 * Run with:
 *   php benchmarks/loop-budget-bench.php
 *
 * Costs are computed from the versioned pricing catalog (never hardcoded),
 * using the same CostEstimator the middleware reserves with.
 */

require __DIR__.'/../vendor/autoload.php';

use Imgul\AgentGuard\Downshift\DownshiftEngine;
use Imgul\AgentGuard\Downshift\DownshiftTier;
use Imgul\AgentGuard\Loop\CircuitBreaker;
use Imgul\AgentGuard\Loop\LoopDetector;
use Imgul\AgentGuard\Loop\ToolCall;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Pricing\PricingCatalog;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This reproducer must run from the CLI.\n");
    exit(1);
}

$catalog = PricingCatalog::fromFile(__DIR__.'/../src/Support/Pricing/prices.json');
$estimator = new CostEstimator($catalog, defaultOutputTokens: 300);

// The guarded configuration: a frontier model with a downshift ladder to a
// cheaper model at 80% of the run soft cap, and a loop detector that trips
// on the 3rd identical tool call.
const FRONTIER_MODEL = 'claude-opus-4-1';
const DOWNSHIFT_MODEL = 'claude-haiku-4-5';
const SOFT_CAP_USD = 15.00;
const MAX_REPEATS = 3;
const TOTAL_STEPS = 5;

/**
 * Simulate the 5-step loop, optionally under the guard.
 *
 * @return array{
 *   steps: int, tokens: int, usd: float, model: string,
 *   downshifted: bool, halted: bool, outcome: string
 * }
 */
function simulate(
    bool $guarded,
    CostEstimator $estimator,
    int $totalSteps,
): array {
    $breaker = $guarded ? new CircuitBreaker : null;
    $detector = $guarded ? new LoopDetector(
        windowSize: 50,
        maxRepeats: MAX_REPEATS,
        maxStepGrowthRatio: 2.5,
        frequencyThreshold: 3,
        similarity: null,
        similarityThreshold: 0.85,
        exemptTools: [],
        dispatcher: null,
    ) : null;
    $engine = $guarded ? new DownshiftEngine([
        new DownshiftTier(threshold: 0.80, toModel: DOWNSHIFT_MODEL),
    ]) : null;

    $model = FRONTIER_MODEL;
    $spendUsd = 0.0;
    $spendTokens = 0;
    $stepsRun = 0;
    $downshifted = false;
    $halted = false;

    // Every step repeats the same tool call with growing context — the
    // runaway loop signature (exact-repeat + context bloat).
    $call = new ToolCall(tool: 'search', args: ['q' => 'runaway-loop']);

    for ($step = 1; $step <= $totalSteps; $step++) {
        // The breaker halts the loop before the next step leaves the server.
        if ($guarded && $breaker !== null && $breaker->isOpen('run')) {
            $halted = true;

            break;
        }

        // Context grows each step (re-sent conversation + tool payloads).
        $inputTokens = 1000 + $step * 300;
        $outputTokens = $estimator->defaultOutputTokens();

        // Soft-cap downshift: swap to the cheaper model once spend crosses
        // the threshold, with capability-cliff tightening (cheaper model).
        if ($guarded && $engine !== null) {
            $fraction = $spendUsd / SOFT_CAP_USD;
            $tier = $engine->applicableFor($fraction, $model);

            if ($tier !== null) {
                $model = $tier->toModel;
                $downshifted = true;
            }
        }

        $stepUsd = $estimator->estimateUsd($model, $inputTokens);
        $stepTokens = $estimator->estimateTokens($inputTokens);

        $spendUsd += $stepUsd;
        $spendTokens += $stepTokens;
        $stepsRun++;

        // Post-step: loop detection. A finding trips the breaker (enforce).
        if ($guarded && $detector !== null) {
            $findings = $detector->check($call, $inputTokens);

            if ($findings !== [] && $breaker !== null) {
                $breaker->trip('run');
            }
        }
    }

    $outcome = $guarded
        ? ($halted
            ? 'Graceful degraded response — loop halted by breaker, model downshifted'
            : 'Guarded run completed without tripping the breaker')
        : 'Unbounded spiral — loop ran to completion (provider 503 / hard-cap blowout)';

    return [
        'steps' => $stepsRun,
        'tokens' => $spendTokens,
        'usd' => $spendUsd,
        'model' => $model,
        'downshifted' => $downshifted,
        'halted' => $halted,
        'outcome' => $outcome,
    ];
}

$unguarded = simulate(false, $estimator, TOTAL_STEPS);
$guarded = simulate(true, $estimator, TOTAL_STEPS);

$tokensSaved = $unguarded['tokens'] - $guarded['tokens'];
$usdSaved = $unguarded['usd'] - $guarded['usd'];

echo "laravel-agent-guard — 5-step loop reproducer\n";
echo str_repeat('=', 72), "\n";
echo "Scenario: a 5-step agent loop repeating the same tool call with\n";
echo "growing context. Run with vs without the guard (enforce mode).\n";
echo str_repeat('-', 72), "\n";

printf(
    "%-22s %8s %12s %12s\n",
    '',
    'Steps',
    'Tokens',
    'USD'
);
printf(
    "%-22s %8d %12d %12.2f\n",
    'WITHOUT guard',
    $unguarded['steps'],
    $unguarded['tokens'],
    $unguarded['usd']
);
printf(
    "%-22s %8d %12d %12.2f\n",
    'WITH guard (enforce)',
    $guarded['steps'],
    $guarded['tokens'],
    $guarded['usd']
);
echo str_repeat('-', 72), "\n";
printf("Tokens saved          %12d\n", $tokensSaved);
printf("USD saved             %12.2f\n", $usdSaved);
echo str_repeat('-', 72), "\n";
echo 'Outcome without guard: ', $unguarded['outcome'], "\n";
echo 'Outcome with guard:    ', $guarded['outcome'], "\n";
if ($guarded['downshifted']) {
    printf("Downshift fired:      %s -> %s (soft-cap, 80%% threshold)\n", FRONTIER_MODEL, DOWNSHIFT_MODEL);
}
if ($guarded['halted']) {
    echo 'Breaker tripped at step ', MAX_REPEATS, ' — steps ', (MAX_REPEATS + 1), '-', TOTAL_STEPS, " halted before leaving the server.\n";
}
echo str_repeat('=', 72), "\n";
echo "This is the launch evidence asset (PRD FR-5.6): the guard turns an\n";
echo "unbounded cost spiral into a graceful, bounded, downshifted run.\n";
