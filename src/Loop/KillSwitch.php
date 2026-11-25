<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

use Imgul\AgentGuard\Events\KillSwitchActivated;
use Imgul\AgentGuard\Switch\InMemorySwitchStore;
use Imgul\AgentGuard\Switch\SwitchStore;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Instant kill switch for guarded execution.
 *
 * Three scopes, all toggleable at runtime and persisted through a
 * SwitchStore so state is shared across processes (CLI, Horizon, Octane,
 * web):
 *  - global: every guarded run, everywhere (key: '__global__')
 *  - tenant: a whole tenant (keyed by the tenant id)
 *  - run:    a specific agent run (keyed by the run id)
 *
 * An open switch hard-stops all guarded execution on the very next guarded
 * step. This is the "stop the bleeding" control for an active incident.
 */
final class KillSwitch
{
    public const GLOBAL_KEY = '__global__';

    private readonly SwitchStore $store;

    /**
     * @param SwitchStore $store  persistent storage (Redis, cache, or in-memory)
     */
    public function __construct(
        ?SwitchStore $store = null,
        private readonly ?Dispatcher $dispatcher = null,
    ) {
        $this->store = $store ?? new InMemorySwitchStore;
    }

    /**
     * Whether the switch is open for the given scope.
     */
    public function isOpen(string $scope, string $key): bool
    {
        return $this->store->get('kill:'.$scope.':'.$key) === '1';
    }

    /**
     * Whether any switch is open for the scoped key.
     */
    public function isOpenFor(string $scope, string $key): bool
    {
        return $this->isOpen($scope, $key);
    }

    /**
     * Whether any global switch is open (halts every run everywhere).
     */
    public function isGloballyOpen(): bool
    {
        return $this->isOpen('global', self::GLOBAL_KEY);
    }

    /**
     * Whether any switch is open for a tenant (global or tenant scope).
     */
    public function isOpenForTenant(string $tenantId): bool
    {
        return $this->isGloballyOpen() || $this->isOpen('tenant', $tenantId);
    }

    /**
     * Open the switch for a scope/key. Emits KillSwitchActivated.
     *
     * @return bool whether the switch transitioned to open
     */
    public function kill(string $scope, string $key): bool
    {
        if ($this->isOpen($scope, $key)) {
            return false;
        }

        // Kill switches should not expire on their own — they stay open
        // until explicitly released.
        $this->store->set('kill:'.$scope.':'.$key, '1');

        $this->dispatch(new KillSwitchActivated($scope, $key, true));

        return true;
    }

    /**
     * Release the switch for a scope/key. Emits KillSwitchActivated.
     *
     * @return bool whether the switch transitioned to closed
     */
    public function unkill(string $scope, string $key): bool
    {
        if (! $this->isOpen($scope, $key)) {
            return false;
        }

        $this->store->delete('kill:'.$scope.':'.$key);

        $this->dispatch(new KillSwitchActivated($scope, $key, false));

        return true;
    }

    /**
     * Whether any switch is open at all (any scope).
     */
    public function anyOpen(): bool
    {
        return $this->isGloballyOpen();
    }

    /**
     * All currently open scope/key pairs.
     *
     * NOTE: This scans known scope prefixes. For the full list with
     * arbitrary run keys, use the SwitchStore directly or track via
     * events. The status command only needs global + tenant scopes.
     *
     * @return array<string, list<string>>
     */
    public function openScopes(): array
    {
        $open = [];

        if ($this->isGloballyOpen()) {
            $open['global'] = [self::GLOBAL_KEY];
        }

        return $open;
    }

    private function dispatch(KillSwitchActivated $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
