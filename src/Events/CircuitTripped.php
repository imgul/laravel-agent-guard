<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Events;

use Imgul\AgentGuard\Loop\LoopState;

/**
 * Fired on breaker CLOSED -> OPEN and OPEN -> HALF_OPEN transitions.
 */
final class CircuitTripped
{
    public function __construct(
        public readonly string $key,
        public readonly LoopState $state,
        public readonly int $cooldownSeconds,
    ) {}
}
