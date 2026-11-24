<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Events;

/**
 * Fired when the kill switch is opened or released.
 */
final class KillSwitchActivated
{
    public function __construct(
        public readonly string $scope,
        public readonly string $key,
        public readonly bool $opened,
    ) {}
}
