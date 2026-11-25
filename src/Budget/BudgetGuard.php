<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget;

use Illuminate\Contracts\Events\Dispatcher;
use Imgul\AgentGuard\Events\BudgetHardCapReached;
use Imgul\AgentGuard\Events\BudgetSoftCapReached;
use Imgul\AgentGuard\Exceptions\BudgetExceededException;
use Imgul\AgentGuard\Support\TextUsage;

/**
 * BudgetGuard: multi-tenant token/USD budget enforcement.
 *
 * Four scopes (run / agent / tenant / tool), each enforceable in tokens and
 * USD (PRD FR-2.1). Reservations are pessimistic and atomic (ledger-dependent),
 * reconciled with exact usage after the step (FR-2.2, FR-2.3).
 *
 * The three-stage action ladder (FR-2.4):
 *   observe -> soft-cap downshift -> hard kill
 * Each stage is independently configurable. Observe records + events only;
 * soft-cap signals the caller to downshift; hard-cap halts with a
 * machine-readable denial.
 *
 * Budget keys derive from tenant/principal context — never agent-supplied
 * values (FR-2.6, ARCHITECTURE §10). Each scope keeps separate USD and
 * token ledgers (the unit is part of the key) so the two never collide.
 */
final class BudgetGuard
{
    public const string UNIT_USD = 'usd';

    public const string UNIT_TOKENS = 'tokens';

    /**
     * Per-scope-per-unit cap configuration: unit -> [softCap, hardCap].
     *
     * @var array<string, array<string, array{soft: float, hard: float}>>
     */
    private array $caps = [];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly bool $enforce = false,
        private readonly ?Dispatcher $dispatcher = null,
    ) {}

    /**
     * Configure a scope's soft/hard caps in a unit. A hard cap of 0 means
     * unlimited. A soft cap of 0 means no soft-cap signal (skip downshift
     * trigger).
     */
    public function cap(BudgetScope $scope, float $softCap, float $hardCap, string $unit = self::UNIT_USD): self
    {
        $this->caps[$scope->value][$unit] = ['soft' => $softCap, 'hard' => $hardCap];

        return $this;
    }

    /**
     * Whether a scope/unit has a configured hard cap.
     */
    public function hasHardCap(BudgetScope $scope, string $unit = self::UNIT_USD): bool
    {
        return isset($this->caps[$scope->value][$unit]['hard']) && $this->caps[$scope->value][$unit]['hard'] > 0;
    }

    /**
     * Reserve cost against a scope/unit's caps.
     *
     * In observe mode this records and emits events but never denies.
     * In enforce mode a hard-cap breach halts with BudgetExceededException.
     *
     * @param  list<string>  $keyParts  tenant/agent/period segments (tenant-derived)
     */
    public function reserve(BudgetScope $scope, array $keyParts, float $cost, string $unit = self::UNIT_USD): BudgetResult
    {
        $caps = $this->caps[$scope->value][$unit] ?? ['soft' => 0.0, 'hard' => 0.0];

        $reservation = new BudgetReservation(
            scope: $scope,
            keyParts: array_merge([$unit], $keyParts),
            cost: $cost,
            softCap: $caps['soft'],
            hardCap: $caps['hard'],
            ttlSeconds: $this->ttlFor($scope),
        );

        $result = $this->ledger->reserve($reservation);

        if ($result->softCapExceeded) {
            $this->dispatcher?->dispatch(new BudgetSoftCapReached(
                scope: $scope,
                key: $result->key,
                spent: $result->spentAfter,
                cap: $caps['soft'],
                estimated: $cost,
            ));
        }

        if ($result->isDenied()) {
            $this->dispatcher?->dispatch(new BudgetHardCapReached(
                scope: $scope,
                key: $result->key,
                spent: $result->spentAfter,
                cap: $caps['hard'],
            ));

            if ($this->enforce) {
                throw new BudgetExceededException(
                    scope: $scope->value,
                    key: $result->key,
                    spent: $result->spentAfter,
                    cap: $caps['hard'],
                );
            }
        }

        return $result;
    }

    /**
     * Reconcile a prior reservation with the exact usage after the step.
     *
     * Returns the reservation excess released back to the ledger.
     *
     * @param  list<string>  $keyParts
     */
    public function reconcile(BudgetScope $scope, array $keyParts, float $reserved, TextUsage $usage, float $unitCost, string $unit = self::UNIT_USD): float
    {
        $actual = $unit === self::UNIT_USD
            ? $unitCost * $usage->totalTokens() / 1000.0
            : (float) $usage->totalTokens();

        $reservation = new BudgetReservation(
            scope: $scope,
            keyParts: array_merge([$unit], $keyParts),
            cost: $reserved,
            softCap: 0.0,
            hardCap: 0.0,
            ttlSeconds: $this->ttlFor($scope),
        );

        return $this->ledger->reconcile($reservation, $actual);
    }

    /**
     * Current spend for a scope/unit key.
     *
     * @param  list<string>  $keyParts
     */
    public function spent(BudgetScope $scope, array $keyParts, string $unit = self::UNIT_USD): float
    {
        return $this->ledger->spent(
            'agent-guard:budget:'.$scope->value.':'.$unit.':'.implode(':', $keyParts),
        );
    }

    /**
     * TTL for a scope's period key. Daily scopes roll at period end.
     */
    private function ttlFor(BudgetScope $scope): int
    {
        if ($scope->isDaily()) {
            // Seconds until end of the current day (UTC).
            return max(1, (int) (strtotime('tomorrow') - time()));
        }

        // Run scope: 24h default; the run itself should reconcile promptly.
        return 86400;
    }
}
