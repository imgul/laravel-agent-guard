<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Testing;

use Imgul\AgentGuard\Events\BudgetHardCapReached;
use Imgul\AgentGuard\Events\BudgetSoftCapReached;
use Imgul\AgentGuard\Events\CircuitTripped;
use Imgul\AgentGuard\Events\KillSwitchActivated;
use Imgul\AgentGuard\Events\LoopDetected;
use Imgul\AgentGuard\Events\ModelDownshifted;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Pricing\PricingCatalog;
use RuntimeException;

/**
 * Test fake for the guard runtime (PRD FR-5.1, API §6).
 *
 * Swapped in via `AgentGuard::fake()` so app-level tests can exercise
 * guarded agent / MCP code without touching real Redis or side-effecting
 * the production event bus. The fake extends `GuardRuntime` with an
 * in-memory ledger and a recording dispatcher, so every decision
 * (allowed / denied / downshifted / tripped) is captured and assertable.
 *
 *   AgentGuard::fake();
 *
 *   // ... exercise agent / MCP code ...
 *
 *   AgentGuard::assertLoopDetected(tool: 'search');
 *   AgentGuard::assertDownshifted(from: 'claude-opus-4-1', to: 'claude-haiku-4-5');
 *   AgentGuard::assertBudgetSoftCapReached(scope: 'tenant');
 *   AgentGuard::assertKillSwitchActivated(tenant: $tenantId);
 *   AgentGuard::assertNothingDenied();
 */
final class AgentGuardFake extends GuardRuntime
{
    private RecordingDispatcher $recorder;

    public function __construct()
    {
        $catalog = PricingCatalog::fromArray([
            'version' => 'fake-1',
            'models' => [
                'gpt-5' => ['input' => 1.25, 'output' => 10.0],
                'gpt-5-mini' => ['input' => 0.25, 'output' => 2.0],
            ],
        ]);

        $this->recorder = new RecordingDispatcher(app('events'));

        parent::__construct(
            ledger: new InMemoryLedger(),
            estimator: new CostEstimator($catalog, defaultOutputTokens: 100),
            similarity: null,
            dispatcher: $this->recorder,
            enforce: false,
            killSwitchEnabled: true,
            loopCooldownSeconds: 60,
        );
    }

    // -----------------------------------------------------------------
    // Assertions
    // -----------------------------------------------------------------

    /**
     * Assert that a LoopDetected event was recorded for the given tool.
     *
     * @throws RuntimeException when no matching event was recorded.
     */
    public function assertLoopDetected(string $tool): void
    {
        $this->expect(
            $this->recorder->recordedMatching(LoopDetected::class, fn (LoopDetected $e): bool => $e->tool === $tool) !== null,
            "Expected a LoopDetected event for tool [{$tool}], but none was recorded.",
        );
    }

    /**
     * Assert that a ModelDownshifted event was recorded with the given
     * from/to model pair.
     *
     * @throws RuntimeException when no matching event was recorded.
     */
    public function assertDownshifted(string $from, string $to): void
    {
        $this->expect(
            $this->recorder->recordedMatching(ModelDownshifted::class, fn (ModelDownshifted $e): bool => $e->fromModel === $from && $e->toModel === $to) !== null,
            "Expected a ModelDownshifted event from [{$from}] to [{$to}], but none was recorded.",
        );
    }

    /**
     * Assert that a BudgetSoftCapReached event was recorded for the given
     * scope.
     *
     * @param  string  $scope  BudgetScope value ('run' | 'agent' | 'tenant' | 'tool').
     * @throws RuntimeException when no matching event was recorded.
     */
    public function assertBudgetSoftCapReached(string $scope): void
    {
        $this->expect(
            $this->recorder->recordedMatching(BudgetSoftCapReached::class, fn (BudgetSoftCapReached $e): bool => $e->scope->value === $scope) !== null,
            "Expected a BudgetSoftCapReached event for scope [{$scope}], but none was recorded.",
        );
    }

    /**
     * Assert that a BudgetHardCapReached event was recorded for the given
     * scope.
     *
     * @param  string  $scope  BudgetScope value.
     * @throws RuntimeException when no matching event was recorded.
     */
    public function assertBudgetHardCapReached(string $scope): void
    {
        $this->expect(
            $this->recorder->recordedMatching(BudgetHardCapReached::class, fn (BudgetHardCapReached $e): bool => $e->scope->value === $scope) !== null,
            "Expected a BudgetHardCapReached event for scope [{$scope}], but none was recorded.",
        );
    }

    /**
     * Assert that a KillSwitchActivated event was recorded for the given
     * tenant.
     *
     * @throws RuntimeException when no matching event was recorded.
     */
    public function assertKillSwitchActivated(string $tenant): void
    {
        $this->expect(
            $this->recorder->recordedMatching(KillSwitchActivated::class, fn (KillSwitchActivated $e): bool => $e->key === $tenant) !== null,
            "Expected a KillSwitchActivated event for [{$tenant}], but none was recorded.",
        );
    }

    /**
     * Assert that a CircuitTripped event was recorded for the given key.
     *
     * @throws RuntimeException when no matching event was recorded.
     */
    public function assertCircuitTripped(string $key): void
    {
        $this->expect(
            $this->recorder->recordedMatching(CircuitTripped::class, fn (CircuitTripped $e): bool => $e->key === $key) !== null,
            "Expected a CircuitTripped event for [{$key}], but none was recorded.",
        );
    }

    /**
     * Assert that no budget denial (hard-cap breach) was recorded.
     *
     * @throws RuntimeException when a BudgetHardCapReached event was recorded.
     */
    public function assertNothingDenied(): void
    {
        $this->expect(
            ! $this->recorder->recordedEvent(BudgetHardCapReached::class),
            'Expected no budget denials, but a BudgetHardCapReached event was recorded.',
        );
    }

    /**
     * All recorded events (for custom assertions).
     *
     * @return list<object>
     */
    public function recordedEvents(): array
    {
        return $this->recorder->recorded();
    }

    private function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
