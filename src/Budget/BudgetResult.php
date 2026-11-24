<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget;

/**
 * Outcome of a budget reservation.
 */
final class BudgetResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $softCapExceeded,
        public readonly string $key,
        public readonly float $spentAfter,
    ) {}

    /**
     * The reservation was allowed and the soft cap was not exceeded.
     */
    public static function allowed(string $key, float $spentAfter): self
    {
        return new self(true, false, $key, $spentAfter);
    }

    /**
     * The reservation was allowed but the soft cap was exceeded — the
     * caller should downshift (never error).
     */
    public static function allowedSoftExceeded(string $key, float $spentAfter): self
    {
        return new self(true, true, $key, $spentAfter);
    }

    /**
     * The reservation was denied — the hard cap would be exceeded.
     */
    public static function denied(string $key, float $spentAfter): self
    {
        return new self(false, false, $key, $spentAfter);
    }

    /**
     * Whether the hard cap was breached and enforcement should halt.
     */
    public function isDenied(): bool
    {
        return ! $this->allowed;
    }
}
