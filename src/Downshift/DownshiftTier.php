<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Downshift;

/**
 * One rung of the downshift ladder (PRD FR-3.2, ARCHITECTURE §6).
 *
 * A tier fires when accumulated spend crosses its threshold (a fraction of
 * the soft cap, 0.0-1.0) and swaps the model, optionally dropping tools and
 * tightening the per-step token ceiling to prevent a degraded-model retry
 * spiral (capability-cliff mitigation).
 */
final class DownshiftTier
{
    /**
     * @param  list<string>  $withoutTools  tool names to drop when this tier fires
     */
    public function __construct(
        public readonly float $threshold,
        public readonly string $toModel,
        public readonly array $withoutTools = [],
        public readonly ?int $maxTokens = null,
    ) {}

    /**
     * Whether this tier should fire at the given spend fraction.
     */
    public function appliesTo(float $spendFraction): bool
    {
        return $this->threshold <= $spendFraction;
    }

    /**
     * Whether applying this tier would actually change the model.
     */
    public function changesModel(string $currentModel): bool
    {
        return $this->toModel !== $currentModel;
    }
}
