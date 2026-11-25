<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

use Imgul\AgentGuard\Events\KillSwitchActivated;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Instant kill switch for guarded execution.
 *
 * Two scopes, both toggleable at runtime:
 *  - run:    a specific agent run (keyed by the run id, derived by the caller)
 *  - tenant: a whole tenant (keyed by the tenant id, derived from the
 *             authenticated principal — never agent-supplied)
 *
 * An open switch hard-stops all guarded execution on the very next guarded
 * step. This is the "stop the bleeding" control for an active incident.
 */
final class KillSwitch
{
    /**
     * @param  array<string, array<string, bool>>  $open
     */
    public function __construct(
        private array $open = [],
        private readonly ?Dispatcher $dispatcher = null,
    ) {}

    /**
     * Whether the switch is open for the given scope.
     */
    public function isOpen(string $scope, string $key): bool
    {
        return $this->open[$scope][$key] ?? false;
    }

    /**
     * Whether any switch is open for the scoped key.
     */
    public function isOpenFor(string $scope, string $key): bool
    {
        return $this->isOpen($scope, $key);
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

        $this->open[$scope][$key] = true;

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

        unset($this->open[$scope][$key]);

        $this->dispatch(new KillSwitchActivated($scope, $key, false));

        return true;
    }

    /**
     * Whether any switch is open at all (any scope).
     */
    public function anyOpen(): bool
    {
        foreach ($this->open as $scopeKeys) {
            if ($scopeKeys !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * All currently open scope/key pairs.
     *
     * @return array<string, list<string>>
     */
    public function openScopes(): array
    {
        $open = [];

        foreach ($this->open as $scope => $keys) {
            if ($keys !== []) {
                $open[$scope] = array_keys($keys);
            }
        }

        return $open;
    }

    private function dispatch(KillSwitchActivated $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
