<?php

declare(strict_types=1);

use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Facades\AgentGuard;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    // Swap in the in-memory runtime so status/budgets read real-shaped
    // ledger values without requiring a Redis connection.
    AgentGuard::fake();
});

it('agent-guard:status reports the runtime overview without a tenant', function (): void {
    $code = Artisan::call('agent-guard:status');

    expect($code)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('laravel-agent-guard status');
    expect($output)->toContain('mode');
    expect($output)->toContain('driver');
});

it('agent-guard:status shows a tenant kill switch as OPEN after a kill', function (): void {
    AgentGuard::kill('tenant-7');

    $code = Artisan::call('agent-guard:status', ['tenant' => 'tenant-7']);

    expect($code)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('tenant-7');
    expect($output)->toContain('OPEN');
});

it('agent-guard:kill opens the kill switch and is reflected in status', function (): void {
    $code = Artisan::call('agent-guard:kill', ['--tenant' => 'tenant-9']);

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Kill switch opened for tenant [tenant-9]');

    Artisan::call('agent-guard:status', ['tenant' => 'tenant-9']);
    expect(Artisan::output())->toContain('OPEN');
});

it('agent-guard:kill --run opens the kill switch for a specific run', function (): void {
    $code = Artisan::call('agent-guard:kill', ['--run' => 'abc123']);

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Kill switch opened for run [abc123]');

    // The run-scope kill switch is open.
    expect(app(GuardRuntime::class)->killSwitch->isOpen('run', 'abc123'))->toBeTrue();
});

it('agent-guard:kill --global opens the global kill switch', function (): void {
    $code = Artisan::call('agent-guard:kill', ['--global' => true]);

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Global kill switch opened');

    // Every tenant should be halted.
    expect(app(GuardRuntime::class)->killSwitch->isGloballyOpen())->toBeTrue();
});

it('agent-guard:kill without options opens the global kill switch', function (): void {
    $code = Artisan::call('agent-guard:kill');

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Global kill switch opened');

    expect(app(GuardRuntime::class)->killSwitch->isGloballyOpen())->toBeTrue();
});

it('agent-guard:kill rejects multiple targeting options', function (): void {
    $code = Artisan::call('agent-guard:kill', [
        '--tenant' => 'acme',
        '--run' => 'abc123',
    ]);

    expect($code)->toBe(1);
    expect(Artisan::output())->toContain('Specify only one');
});

it('agent-guard:budgets reports spend against configured caps', function (): void {
    // Seed the in-memory ledger for the tenant's daily USD budget.
    $fake = AgentGuard::fake();
    $fake->budgetGuard()
        ->cap(BudgetScope::Tenant, softCap: 1.00, hardCap: 15.00)
        ->reserve(BudgetScope::Tenant, ['tenant-1', date('Y-m-d')], 3.25);

    $code = Artisan::call('agent-guard:budgets', ['tenant' => 'tenant-1']);

    expect($code)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Budgets for tenant: tenant-1');
    expect($output)->toContain('tenant_daily_usd');
    // The seeded spend of 3.25 appears in the table.
    expect($output)->toContain('3.25');
});
