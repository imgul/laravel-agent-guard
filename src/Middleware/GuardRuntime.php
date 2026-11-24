<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Middleware;

use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Downshift\DownshiftEngine;
use Imgul\AgentGuard\Loop\CircuitBreaker;
use Imgul\AgentGuard\Loop\KillSwitch;
use Imgul\AgentGuard\Loop\LoopDetector;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Bundle of the runtime engines a GuardAgentSteps middleware needs.
 *
 * Constructed once (in the service provider) and shared across all
 * middleware instances, so the kill switch and circuit breaker are
 * consistent global controls: killing a tenant in one guarded agent
 * halts every other guarded agent for that tenant.
 *
 * The loop detector is intentionally per-run (created fresh by the
 * middleware) because its sliding window is run-scoped state.
 */
final class GuardRuntime
{
    public readonly KillSwitch $killSwitch;

    public readonly CircuitBreaker $breaker;

    public function __construct(
        public readonly Ledger $ledger,
        public readonly CostEstimator $estimator,
        public readonly ?SimilarityStrategy $similarity = null,
        public readonly ?Dispatcher $dispatcher = null,
        public readonly bool $enforce = false,
        public readonly bool $killSwitchEnabled = true,
        public readonly int $loopCooldownSeconds = 60,
        public readonly int $loopWindowSize = 50,
        public readonly int $loopMaxRepeats = 3,
        public readonly float $loopMaxStepGrowthRatio = 2.5,
    ) {
        $this->killSwitch = new KillSwitch(dispatcher: $dispatcher);
        $this->breaker = new CircuitBreaker(
            cooldownSeconds: $loopCooldownSeconds,
            dispatcher: $dispatcher,
        );
    }

    /**
     * Build a LoopDetector for a guarded scope.
     *
     * @param  array<string, true>  $exemptTools
     */
    public function loopDetector(array $exemptTools): LoopDetector
    {
        return new LoopDetector(
            windowSize: $this->loopWindowSize,
            maxRepeats: $this->loopMaxRepeats,
            maxStepGrowthRatio: $this->loopMaxStepGrowthRatio,
            similarity: $this->similarity,
            exemptTools: $exemptTools,
            dispatcher: $this->dispatcher,
        );
    }

    /**
     * Build a BudgetGuard against the ledger.
     */
    public function budgetGuard(): BudgetGuard
    {
        return new BudgetGuard(
            ledger: $this->ledger,
            enforce: $this->enforce,
            dispatcher: $this->dispatcher,
        );
    }

    /**
     * Build a DownshiftEngine from an ordered ladder of tiers.
     *
     * @param  list<DownshiftTier>  $tiers
     */
    public function downshiftEngine(array $tiers): DownshiftEngine
    {
        return new DownshiftEngine($tiers);
    }
}
