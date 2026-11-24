<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Events;

/**
 * Fired when a soft-cap breach applies a downshift tier to a step.
 * Carries before/after model, the tier index, and the reason — redacted
 * (no prompt/tool-arg payloads).
 */
final class ModelDownshifted
{
    public function __construct(
        public readonly string $fromModel,
        public readonly string $toModel,
        public readonly ?int $tier,
        public readonly string $reason,
    ) {}
}
