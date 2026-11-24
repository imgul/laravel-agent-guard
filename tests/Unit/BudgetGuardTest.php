<?php

declare(strict_types=1);

use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetResult;
use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Exceptions\BudgetExceededException;
use Imgul\AgentGuard\Support\TextUsage;

function fakeLedger(array &$spend = []): Ledger
{
    return new class($spend) implements Ledger
    {
        public function __construct(private array &$spend) {}

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
    };
}

it('allows reservations under the hard cap', function (): void {
    $spend = [];
    $guard = (new BudgetGuard(fakeLedger($spend), enforce: false))
        ->cap(BudgetScope::Tenant, softCap: 10.0, hardCap: 15.0);

    $result = $guard->reserve(BudgetScope::Tenant, ['tenant-1', '2026-11-24'], 5.0);

    expect($result->allowed)->toBeTrue()
        ->and($result->softCapExceeded)->toBeFalse()
        ->and($result->spentAfter)->toBe(5.0);
});

it('signals soft-cap breach without denying', function (): void {
    $spend = [];
    $guard = (new BudgetGuard(fakeLedger($spend), enforce: false))
        ->cap(BudgetScope::Tenant, softCap: 8.0, hardCap: 15.0);

    $guard->reserve(BudgetScope::Tenant, ['tenant-1', '2026-11-24'], 5.0);
    $result = $guard->reserve(BudgetScope::Tenant, ['tenant-1', '2026-11-24'], 5.0);

    expect($result->allowed)->toBeTrue()
        ->and($result->softCapExceeded)->toBeTrue()
        ->and($result->spentAfter)->toBe(10.0);
});

it('denies hard-cap breach in observe mode without throwing', function (): void {
    $spend = [];
    $guard = (new BudgetGuard(fakeLedger($spend), enforce: false))
        ->cap(BudgetScope::Tenant, softCap: 8.0, hardCap: 10.0);

    $guard->reserve(BudgetScope::Tenant, ['tenant-1', '2026-11-24'], 6.0);
    $result = $guard->reserve(BudgetScope::Tenant, ['tenant-1', '2026-11-24'], 6.0);

    expect($result->allowed)->toBeFalse()
        ->and($result->isDenied())->toBeTrue();
});

it('throws BudgetExceededException on hard-cap breach in enforce mode', function (): void {
    $spend = [];
    $guard = (new BudgetGuard(fakeLedger($spend), enforce: true))
        ->cap(BudgetScope::Tenant, softCap: 8.0, hardCap: 10.0);

    $guard->reserve(BudgetScope::Tenant, ['tenant-1', '2026-11-24'], 6.0);

    try {
        $guard->reserve(BudgetScope::Tenant, ['tenant-1', '2026-11-24'], 6.0);
        throw new AssertionError('Expected BudgetExceededException');
    } catch (BudgetExceededException $e) {
        expect($e->scope)->toBe('tenant')
            ->and($e->cap)->toBe(10.0);
    }
});

it('scopes budgets per tenant independently', function (): void {
    $spend = [];
    $guard = (new BudgetGuard(fakeLedger($spend), enforce: false))
        ->cap(BudgetScope::Tenant, softCap: 8.0, hardCap: 10.0);

    $guard->reserve(BudgetScope::Tenant, ['tenant-a', '2026-11-24'], 9.0);
    $result = $guard->reserve(BudgetScope::Tenant, ['tenant-b', '2026-11-24'], 9.0);

    expect($result->allowed)->toBeTrue()
        ->and($guard->spent(BudgetScope::Tenant, ['tenant-a', '2026-11-24']))->toBe(9.0);
});

it('reconciles a reservation with actual usage', function (): void {
    $spend = [];
    $guard = (new BudgetGuard(fakeLedger($spend), enforce: false))
        ->cap(BudgetScope::Run, softCap: 0.0, hardCap: 5.0);

    $guard->reserve(BudgetScope::Run, ['run-1'], 0.50);

    // Reserved $0.50 for 500 tokens at $1/1k; actual usage 300 tokens = $0.30.
    $excess = $guard->reconcile(
        BudgetScope::Run,
        ['run-1'],
        0.50,
        new TextUsage(inputTokens: 200, outputTokens: 100),
        1.0,
    );

    expect($excess)->toBe(0.20)
        ->and($guard->spent(BudgetScope::Run, ['run-1']))->toBe(0.30);
});

it('reports whether a scope has a hard cap', function (): void {
    $guard = new BudgetGuard(fakeLedger([]));

    expect($guard->hasHardCap(BudgetScope::Tenant))->toBeFalse();

    $guard->cap(BudgetScope::Tenant, softCap: 1.0, hardCap: 10.0);

    expect($guard->hasHardCap(BudgetScope::Tenant))->toBeTrue();
});
