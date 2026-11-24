<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string mode()
 * @method static bool isObserving()
 * @method static bool isEnforcing()
 * @method static string driver()
 * @method static mixed config(string $key, mixed $default = null)
 * @method static bool kill(string $tenantId)
 * @method static bool unkill(string $tenantId)
 * @method static array<string, mixed> status(string $tenantId)
 * @method static array<string, array{cap: float|null, spent: float}> budgets(string $tenantId)
 * @method static string reconcile(string $runId)
 * @method static \Imgul\AgentGuard\Testing\AgentGuardFake fake()
 * @method static \Imgul\AgentGuard\Testing\AgentGuardFake|null fakeOrNull()
 * @method static void assertLoopDetected(string $tool)
 * @method static void assertDownshifted(string $from, string $to)
 * @method static void assertBudgetSoftCapReached(string $scope)
 * @method static void assertBudgetHardCapReached(string $scope)
 * @method static void assertKillSwitchActivated(string $tenant)
 * @method static void assertCircuitTripped(string $key)
 * @method static void assertNothingDenied()
 *
 * @see \Imgul\AgentGuard\AgentGuard
 */
final class AgentGuard extends Facade
{
    /**
     * Get the registered name of the component in the service container.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'agent-guard';
    }
}
