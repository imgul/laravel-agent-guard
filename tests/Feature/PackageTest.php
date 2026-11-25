<?php

declare(strict_types=1);

use Imgul\AgentGuard\AgentGuard;
use Imgul\AgentGuard\Facades\AgentGuard as AgentGuardFacade;

it('registers the agent-guard container and facade', function (): void {
    expect(AgentGuardFacade::mode())->toBe('observe');
});

it('merges the default configuration', function (): void {
    expect(config('agent-guard.mode'))->toBe('observe');
    expect(config('agent-guard.driver'))->toBe('redis');
    expect(config('agent-guard.loop.max_repeats'))->toBe(3);
    expect(config('agent-guard.budget.defaults.tenant_daily_usd'))->toBe(15.00);
});

it('resolves configuration through the guard', function (): void {
    $guard = app(AgentGuard::class);

    expect($guard->config('loop.max_repeats'))->toBe(3);
    expect($guard->config('budget.action_ladder.hard'))->toBe('halt');
    expect($guard->isObserving())->toBeTrue();
    expect($guard->isEnforcing())->toBeFalse();
    expect($guard->driver())->toBe('redis');
});

it('enforces observe-by-default behavior', function (): void {
    config()->set('agent-guard.mode', 'enforce');

    expect(app(AgentGuard::class)->isEnforcing())->toBeTrue();
    expect(AgentGuardFacade::isEnforcing())->toBeTrue();
});
