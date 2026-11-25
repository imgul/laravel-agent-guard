<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Middleware;

use Illuminate\Contracts\Events\Dispatcher;
use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Downshift\DownshiftEngine;
use Imgul\AgentGuard\Downshift\DownshiftTier;
use Imgul\AgentGuard\Loop\CircuitBreaker;
use Imgul\AgentGuard\Loop\KillSwitch;
use Imgul\AgentGuard\Loop\LoopDetector;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;
use Imgul\AgentGuard\Switch\SwitchStore;

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
class GuardRuntime
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
        ?SwitchStore $switchStore = null,
    ) {
        $this->killSwitch = new KillSwitch(store: $switchStore, dispatcher: $dispatcher);
        $this->breaker = new CircuitBreaker(
            store: $switchStore,
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
     * Get a cached LoopDetector for a persistent guarded scope.
     *
     * Unlike loopDetector(), the returned detector is memoized by scope
     * for the lifetime of this runtime, so stateful loop detection works
     * across multiple stateless requests — the MCP tool case, where each
     * tool call is an independent JSON-RPC request that must still share
     * one sliding window per tool+principal.
     *
     * @param  array<string, true>  $exemptTools
     * @param  int|null  $maxRepeats  Override the runtime default for this scope.
     */
    public function loopDetectorFor(string $scope, array $exemptTools = [], ?int $maxRepeats = null): LoopDetector
    {
        return $this->loopDetectors[$scope] ??= new LoopDetector(
            windowSize: $this->loopWindowSize,
            maxRepeats: $maxRepeats ?? $this->loopMaxRepeats,
            maxStepGrowthRatio: $this->loopMaxStepGrowthRatio,
            similarity: $this->similarity,
            exemptTools: $exemptTools,
            dispatcher: $this->dispatcher,
        );
    }

    /**
     * @var array<string, LoopDetector>
     */
    private array $loopDetectors = [];

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
