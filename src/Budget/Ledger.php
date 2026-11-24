<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget;

/**
 * Budget ledger driver contract.
 *
 * Two implementations:
 *  - RedisLedger: atomic Lua reservations (race-proof under concurrency)
 *  - DatabaseLedger: fallback with documented weaker concurrency
 */
interface Ledger
{
    /**
     * Pessimistically reserve cost against the scope's caps.
     *
     * Returns allowed / allowed-with-soft-exceeded / denied.
     */
    public function reserve(BudgetReservation $reservation): BudgetResult;

    /**
     * Reconcile a prior reservation with the actual spend, returning the
     * reservation excess (reserved - actual) so the caller can release it.
     *
     * A negative excess means the actual spend exceeded the reservation and
     * the ledger has already been adjusted upward.
     */
    public function reconcile(BudgetReservation $reservation, float $actualCost): float;

    /**
     * Current spend recorded for a budget key.
     */
    public function spent(string $key): float;
}
