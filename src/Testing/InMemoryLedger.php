<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Testing;

use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetResult;
use Imgul\AgentGuard\Budget\Ledger;

/**
 * An in-memory Ledger for AgentGuardFake. Records reservations and
 * reconciliations in process memory — no Redis, no database. Spend is
 * tracked per reservation key in its own state, matching the contract of
 * the Phase 2 RedisLedger so the fake behaves identically for testing.
 */
final class InMemoryLedger implements Ledger
{
    /** @var array<string, float> */
    private array $spend = [];

    public function reserve(BudgetReservation $reservation): BudgetResult
    {
        $key = $reservation->key();
        $spent = $this->spend[$key] ?? 0.0;

        if ($reservation->hardCap > 0 && $spent + $reservation->cost > $reservation->hardCap) {
            return BudgetResult::denied($key, $spent);
        }

        $spent += $reservation->cost;
        $this->spend[$key] = $spent;

        $soft = $reservation->softCap > 0 && $spent > $reservation->softCap;

        return $soft ? BudgetResult::allowedSoftExceeded($key, $spent) : BudgetResult::allowed($key, $spent);
    }

    public function reconcile(BudgetReservation $reservation, float $actualCost): float
    {
        $key = $reservation->key();
        $excess = $reservation->cost - $actualCost;
        $this->spend[$key] = ($this->spend[$key] ?? 0.0) - $excess;

        return $excess;
    }

    public function spent(string $key): float
    {
        return $this->spend[$key] ?? 0.0;
    }
}
