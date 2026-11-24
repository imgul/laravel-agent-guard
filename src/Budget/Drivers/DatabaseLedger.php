<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget\Drivers;

use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetResult;
use Imgul\AgentGuard\Budget\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed budget ledger — the no-Redis fallback (PRD FR-2.8).
 *
 * Enforces budgets via a budgets table with row-level locking
 * (SELECT ... FOR UPDATE). This is the documented weaker-concurrency path:
 * DB row locks cannot match Redis's single-round-trip atomicity under high
 * concurrency, so Redis is the recommended production driver.
 */
final class DatabaseLedger implements Ledger
{
    private const TABLE = 'agent_guard_budgets';

    public function reserve(BudgetReservation $reservation): BudgetResult
    {
        $key = $reservation->key();

        return DB::transaction(function () use ($reservation, $key): BudgetResult {
            /** @var array<string, mixed>|null $row */
            $row = DB::table(self::TABLE)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            $spent = $row['spent'] ?? 0.0;
            $spent = (float) $spent;

            if ($reservation->hardCap > 0 && $spent + $reservation->cost > $reservation->hardCap) {
                return BudgetResult::denied($key, $spent);
            }

            $spent += $reservation->cost;

            if ($row === null) {
                DB::table(self::TABLE)->insert([
                    'key' => $key,
                    'spent' => $spent,
                    'expires_at' => now()->addSeconds($reservation->ttlSeconds),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table(self::TABLE)
                    ->where('key', $key)
                    ->update([
                        'spent' => $spent,
                        'expires_at' => now()->addSeconds($reservation->ttlSeconds),
                        'updated_at' => now(),
                    ]);
            }

            $softExceeded = $reservation->softCap > 0
                && $spent > $reservation->softCap;

            return $softExceeded
                ? BudgetResult::allowedSoftExceeded($key, $spent)
                : BudgetResult::allowed($key, $spent);
        });
    }

    public function reconcile(BudgetReservation $reservation, float $actualCost): float
    {
        $key = $reservation->key();
        $excess = $reservation->cost - $actualCost;

        if ($excess === 0.0) {
            return 0.0;
        }

        DB::table(self::TABLE)
            ->where('key', $key)
            ->update([
                'spent' => DB::raw('spent - '.(float) $excess),
                'updated_at' => now(),
            ]);

        return $excess;
    }

    public function spent(string $key): float
    {
        /** @var array<string, mixed>|null $row */
        $row = DB::table(self::TABLE)->where('key', $key)->first();

        return $row === null ? 0.0 : (float) $row['spent'];
    }
}
