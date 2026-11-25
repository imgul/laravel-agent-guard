<?php

declare(strict_types=1);

namespace Imgul\AgentGuard;

use Illuminate\Support\Arr;
use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Testing\AgentGuardFake;

/**
 * Core runtime container for laravel-agent-guard.
 *
 * Holds the merged configuration and the global mode. The loop, budget, and
 * downshift engines are attached to this container as development proceeds
 * (see PLAN phases 1-5); the facade and middleware always resolve this
 * single instance, so wiring is centralized in one place.
 *
 * Operational controls (kill switch, status, budgets) delegate to the
 * shared `GuardRuntime` singleton. Test fakes are swapped in via `fake()`
 * (API §6) so app-level tests run without Redis or side effects.
 */
final class AgentGuard
{
    /**
     * The active fake (null when not faking).
     */
    private ?AgentGuardFake $fake = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * The active mode: "observe" (events only, no enforcement) or "enforce".
     */
    public function mode(): string
    {
        return $this->stringConfig('mode', 'observe');
    }

    /**
     * Whether the guard is running in observe mode (detection + events only).
     */
    public function isObserving(): bool
    {
        return $this->mode() === 'observe';
    }

    /**
     * Whether the guard is running in enforce mode (action ladder active).
     */
    public function isEnforcing(): bool
    {
        return $this->mode() === 'enforce';
    }

    /**
     * The configured ledger driver ("redis" or "database").
     */
    public function driver(): string
    {
        return $this->stringConfig('driver', 'redis');
    }

    /**
     * Read a configuration value using dot-notation.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        return Arr::get($this->config, $key, $default);
    }

    // -----------------------------------------------------------------
    // Operational controls (delegate to GuardRuntime)
    // -----------------------------------------------------------------

    /**
     * Open the kill switch for a tenant (incident response).
     *
     * @return bool whether the switch transitioned to open.
     */
    public function kill(string $tenantId): bool
    {
        return $this->runtime()->killSwitch->kill('tenant', $tenantId);
    }

    /**
     * Release the kill switch for a tenant.
     *
     * @return bool whether the switch transitioned to closed.
     */
    public function unkill(string $tenantId): bool
    {
        return $this->runtime()->killSwitch->unkill('tenant', $tenantId);
    }

    /**
     * Per-tenant status: mode, driver, kill-switch state, breaker state,
     * and budget spend vs configured caps.
     *
     * @return array{mode: string, driver: string, kill_switch: bool, breaker: string, budgets: array<string, array{cap: float|null, spent: float>}}
     */
    public function status(string $tenantId): array
    {
        $runtime = $this->runtime();

        return [
            'mode' => $this->mode(),
            'driver' => $this->driver(),
            'kill_switch' => $runtime->killSwitch->isOpenFor('tenant', $tenantId),
            'breaker' => $runtime->breaker->state('run:'.$tenantId)->value,
            'budgets' => $this->budgets($tenantId),
        ];
    }

    /**
     * Per-scope spend summary for a tenant, against the configured caps.
     *
     * Only USD and token caps are read from the ledger (keys like
     * `tool_daily_calls` are call-count caps tracked elsewhere). The
     * key-part mapping mirrors GuardAgentSteps::keyPartsFor so the values
     * reported match what the middleware actually writes.
     *
     * @return array<string, array{cap: float|null, spent: float}>
     */
    public function budgets(string $tenantId): array
    {
        $runtime = $this->runtime();
        $defaults = (array) $this->config('budget.defaults', []);

        /** @var array<string, array{cap: float|null, spent: float}> $result */
        $result = [];

        foreach ($defaults as $key => $cap) {
            $scope = $this->budgetScope($key);
            $unit = $this->budgetUnit($key);

            // Skip call-count and other non-ledger caps.
            if ($scope === null || ! in_array($unit, [BudgetGuard::UNIT_USD, BudgetGuard::UNIT_TOKENS], true)) {
                continue;
            }

            $ledgerKey = $this->budgetKey($scope, $unit, $tenantId);

            $result[$key] = [
                'cap' => is_numeric($cap) ? (float) $cap : null,
                'spent' => $runtime->ledger->spent($ledgerKey),
            ];
        }

        return $result;
    }

    /**
     * Force reconciliation of a run's reservation.
     *
     * Reconciliation is automatic per-step (the middleware reconciles each
     * step's pessimistic reservation against exact usage). This method is
     * a no-op hook for future manual reconciliation; it returns the
     * runtime's current breaker state for the run as a diagnostic.
     *
     * @return string  the breaker state for the run.
     */
    public function reconcile(string $runId): string
    {
        return $this->runtime()->breaker->state('run:'.$runId)->value;
    }

    // -----------------------------------------------------------------
    // Testing (API §6)
    // -----------------------------------------------------------------

    /**
     * Swap in the AgentGuardFake as the GuardRuntime binding, so guarded
     * agent / MCP code resolves the fake instead of the real runtime.
     *
     * @return AgentGuardFake  the active fake (for direct assertions).
     */
    public function fake(): AgentGuardFake
    {
        $this->fake = new AgentGuardFake;

        app()->instance(GuardRuntime::class, $this->fake);

        return $this->fake;
    }

    /**
     * The active fake, or null when not faking.
     */
    public function fakeOrNull(): ?AgentGuardFake
    {
        return $this->fake;
    }

    /**
     * Assert that a LoopDetected event was recorded for the given tool.
     *
     * @throws \RuntimeException when no matching event was recorded.
     */
    public function assertLoopDetected(string $tool): void
    {
        $this->requireFake()->assertLoopDetected($tool);
    }

    /**
     * Assert that a ModelDownshifted event was recorded with the given
     * from/to model pair.
     *
     * @throws \RuntimeException when no matching event was recorded.
     */
    public function assertDownshifted(string $from, string $to): void
    {
        $this->requireFake()->assertDownshifted($from, $to);
    }

    /**
     * Assert that a BudgetSoftCapReached event was recorded for the given
     * scope.
     *
     * @throws \RuntimeException when no matching event was recorded.
     */
    public function assertBudgetSoftCapReached(string $scope): void
    {
        $this->requireFake()->assertBudgetSoftCapReached($scope);
    }

    /**
     * Assert that a BudgetHardCapReached event was recorded for the given
     * scope.
     *
     * @throws \RuntimeException when no matching event was recorded.
     */
    public function assertBudgetHardCapReached(string $scope): void
    {
        $this->requireFake()->assertBudgetHardCapReached($scope);
    }

    /**
     * Assert that a KillSwitchActivated event was recorded for the given
     * tenant.
     *
     * @throws \RuntimeException when no matching event was recorded.
     */
    public function assertKillSwitchActivated(string $tenant): void
    {
        $this->requireFake()->assertKillSwitchActivated($tenant);
    }

    /**
     * Assert that a CircuitTripped event was recorded for the given key.
     *
     * @throws \RuntimeException when no matching event was recorded.
     */
    public function assertCircuitTripped(string $key): void
    {
        $this->requireFake()->assertCircuitTripped($key);
    }

    /**
     * Assert that no budget denial was recorded.
     *
     * @throws \RuntimeException when a BudgetHardCapReached event was recorded.
     */
    public function assertNothingDenied(): void
    {
        $this->requireFake()->assertNothingDenied();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Resolve the shared GuardRuntime from the container.
     */
    private function runtime(): GuardRuntime
    {
        return app(GuardRuntime::class);
    }

    /**
     * Get the active fake or throw if fake() was never called.
     */
    private function requireFake(): AgentGuardFake
    {
        if ($this->fake === null) {
            throw new \RuntimeException('AgentGuard::fake() must be called before using assertion methods.');
        }

        return $this->fake;
    }

    /**
     * Read a string configuration value, falling back to a default when the
     * stored value is not a string. Avoids casting `mixed` directly.
     */
    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Ledger key parts for a scope, tenant-derived — mirrors
     * GuardAgentSteps::keyPartsFor exactly so status reads the same keys
     * the middleware writes.
     *
     * @return list<string>
     */
    private function keyPartsFor(string $scope, string $tenantId): array
    {
        return match ($scope) {
            'tenant' => [$tenantId, date('Y-m-d')],
            'agent' => ['agent'],
            'tool' => ['agent'],
            default => ['run:'.$tenantId],
        };
    }

    /**
     * Build the full ledger key the BudgetGuard writes for a scope/unit.
     * The unit is prepended to keyParts (see BudgetReservation::key).
     */
    private function budgetKey(string $scope, string $unit, string $tenantId): string
    {
        $keyParts = array_merge([$unit], $this->keyPartsFor($scope, $tenantId));

        return 'agent-guard:budget:'.$scope.':'.implode(':', $keyParts);
    }

    /**
     * Parse the scope segment from a budget config key.
     *
     * Keys follow the pattern {scope}_{unit}[_{period}], e.g.
     * `tenant_daily_usd` -> scope 'tenant'. Returns null for keys that
     * don't map to a ledger scope.
     */
    private function budgetScope(string $key): ?string
    {
        $scope = strtok($key, '_');

        return in_array($scope, ['run', 'agent', 'tenant', 'tool'], true) ? $scope : null;
    }

    /**
     * Parse the unit segment from a budget config key.
     *
     * Keys are `{scope}_{unit}` or `{scope}_{period}_{unit}`, so the unit
     * is always the final segment: `run_usd` -> 'usd';
     * `tenant_daily_tokens` -> 'tokens'; `tool_daily_calls` -> 'calls'.
     */
    private function budgetUnit(string $key): string
    {
        $segments = explode('_', $key);

        return $segments[count($segments) - 1];
    }
}
