<?php

declare(strict_types=1);

use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Events\ModelDownshifted;
use Imgul\AgentGuard\Facades\AgentGuard;
use Imgul\AgentGuard\Loop\ToolCall;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Testing\AgentGuardFake;

beforeEach(function (): void {
    AgentGuard::fake();
});

it('swaps in the fake as the guard runtime binding', function (): void {
    expect(app(GuardRuntime::class))->toBeInstanceOf(AgentGuardFake::class);
    expect(AgentGuard::fakeOrNull())->toBeInstanceOf(AgentGuardFake::class);
});

it('records and asserts a kill switch activation', function (): void {
    expect(AgentGuard::kill('tenant-42'))->toBeTrue();

    AgentGuard::assertKillSwitchActivated('tenant-42');

    // A second kill of the same tenant is a no-op (already open).
    expect(AgentGuard::kill('tenant-42'))->toBeFalse();
});

it('records and asserts a loop detection for a tool', function (): void {
    $fake = AgentGuard::fakeOrNull();
    $detector = $fake->loopDetector([]);

    $call = new ToolCall(tool: 'search', args: ['q' => 'loop']);

    // ExactRepeatDetector trips on the maxRepeats-th identical call (3).
    $detector->check($call, 10);
    $detector->check($call, 10);
    $detector->check($call, 10);

    expect(fn () => AgentGuard::assertLoopDetected('search'))->not->toThrow(\Throwable::class);
});

it('records and asserts a budget soft-cap reach', function (): void {
    $fake = AgentGuard::fakeOrNull();
    $guard = $fake->budgetGuard();
    $guard->cap(BudgetScope::Tenant, softCap: 1.00, hardCap: 2.00);

    // First reservation (0.50) is under the soft cap; the second (0.75)
    // pushes spend past it.
    $guard->reserve(BudgetScope::Tenant, ['tenant-1', date('Y-m-d')], 0.50);
    $guard->reserve(BudgetScope::Tenant, ['tenant-1', date('Y-m-d')], 0.75);

    expect(fn () => AgentGuard::assertBudgetSoftCapReached('tenant'))->not->toThrow(\Throwable::class);
    expect(fn () => AgentGuard::assertNothingDenied())->not->toThrow(\Throwable::class);
});

it('records and asserts a budget hard-cap reach (observe, no throw)', function (): void {
    $fake = AgentGuard::fakeOrNull();
    $guard = $fake->budgetGuard();
    $guard->cap(BudgetScope::Tenant, softCap: 1.00, hardCap: 1.00);

    // Spend past the hard cap in observe mode records but does not throw.
    // A hard-cap breach denies outright, so only a hard-cap event is
    // dispatched (no soft-cap event — the soft check is skipped when denied).
    $guard->reserve(BudgetScope::Tenant, ['tenant-2', date('Y-m-d')], 1.50);

    expect(fn () => AgentGuard::assertBudgetHardCapReached('tenant'))->not->toThrow(\Throwable::class);
});

it('records and asserts a downshift', function (): void {
    $fake = AgentGuard::fakeOrNull();
    $fake->dispatcher?->dispatch(new ModelDownshifted(
        fromModel: 'claude-opus-4-1',
        toModel: 'claude-haiku-4-5',
        tier: 0,
        reason: 'budget-soft-cap',
    ));

    expect(fn () => AgentGuard::assertDownshifted('claude-opus-4-1', 'claude-haiku-4-5'))->not->toThrow(\Throwable::class);
});

it('throws when an assertion has no matching recorded event', function (): void {
    expect(fn () => AgentGuard::assertLoopDetected('search'))
        ->toThrow(\RuntimeException::class, 'Expected a LoopDetected event for tool [search]');

    expect(fn () => AgentGuard::assertDownshifted('a', 'b'))
        ->toThrow(\RuntimeException::class);

    expect(fn () => AgentGuard::assertKillSwitchActivated('nope'))
        ->toThrow(\RuntimeException::class);
});

it('throws when assertions are used without fake()', function (): void {
    // A fresh AgentGuard (not the singleton) has no fake in state.
    $guard = new \Imgul\AgentGuard\AgentGuard([]);

    expect(fn () => $guard->assertNothingDenied())
        ->toThrow(\RuntimeException::class, 'AgentGuard::fake() must be called');

    expect(fn () => $guard->assertLoopDetected('search'))
        ->toThrow(\RuntimeException::class, 'AgentGuard::fake() must be called');
});
