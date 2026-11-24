<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support;

/**
 * Pre-step cost estimate used for the pessimistic budget reservation.
 *
 * Combines the estimated token count (input from message content + a default
 * output allowance) with the estimated USD cost derived from the pricing
 * catalog. Framework-agnostic; the middleware (adapter) fills it before a
 * step runs, and reconciliation replaces it with exact usage afterward.
 */
final class StepEstimate
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $usd,
    ) {}

    /**
     * Total estimated tokens (input + output).
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Whether there is nothing to reserve (no tokens and no USD).
     */
    public function isEmpty(): bool
    {
        return $this->totalTokens() <= 0 && $this->usd <= 0.0;
    }
}
