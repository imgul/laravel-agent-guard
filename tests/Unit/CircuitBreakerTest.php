<?php

declare(strict_types=1);

use Imgul\AgentGuard\Loop\CircuitBreaker;
use Imgul\AgentGuard\Loop\LoopState;

it('starts closed and allows execution', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 60, halfOpenProbe: true);

    expect($breaker->state('run:1'))->toBe(LoopState::Closed);
    expect($breaker->isOpen('run:1'))->toBeFalse();
    expect($breaker->state('run:1')->allowsExecution())->toBeTrue();
});

it('trips closed -> open', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 60, halfOpenProbe: true);

    expect($breaker->trip('run:1'))->toBe(LoopState::Open);
    expect($breaker->isOpen('run:1'))->toBeTrue();
    expect($breaker->state('run:1')->allowsExecution())->toBeFalse();
});

it('trips are scoped per key', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 60, halfOpenProbe: true);

    $breaker->trip('run:1');

    expect($breaker->isOpen('run:1'))->toBeTrue();
    expect($breaker->isOpen('run:2'))->toBeFalse();
});

it('refuses probes before the cooldown elapses', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 60, halfOpenProbe: true);
    $breaker->trip('run:1');

    expect($breaker->allowProbe('run:1'))->toBeFalse();
    expect($breaker->state('run:1'))->toBe(LoopState::Open);
});

it('transitions open -> half_open after cooldown and allows one probe', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 0, halfOpenProbe: true);
    $breaker->trip('run:1');

    expect($breaker->allowProbe('run:1'))->toBeTrue();
    expect($breaker->state('run:1'))->toBe(LoopState::HalfOpen);
    expect($breaker->shouldPruneContext('run:1'))->toBeTrue();
});

it('resets to closed on a successful probe', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 0, halfOpenProbe: true);
    $breaker->trip('run:1');
    $breaker->allowProbe('run:1');

    expect($breaker->probeResult('run:1', true))->toBe(LoopState::Closed);
    expect($breaker->isOpen('run:1'))->toBeFalse();
});

it('re-trips to open on a failed probe', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 0, halfOpenProbe: true);
    $breaker->trip('run:1');
    $breaker->allowProbe('run:1');

    expect($breaker->probeResult('run:1', false))->toBe(LoopState::Open);
    expect($breaker->isOpen('run:1'))->toBeTrue();
});

it('does not probe when half-open probing is disabled', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 0, halfOpenProbe: false);
    $breaker->trip('run:1');

    expect($breaker->allowProbe('run:1'))->toBeFalse();
    expect($breaker->state('run:1'))->toBe(LoopState::Open);
});

it('reset clears a scoped breaker', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 60, halfOpenProbe: true);
    $breaker->trip('run:1');

    $breaker->reset('run:1');

    expect($breaker->state('run:1'))->toBe(LoopState::Closed);
});

it('exposes a snapshot of scoped breaker states', function (): void {
    $breaker = new CircuitBreaker(cooldownSeconds: 60, halfOpenProbe: true);
    $breaker->trip('run:1');
    $breaker->trip('tool:search');

    expect($breaker->snapshot())->toBe([
        'run:1' => 'open',
        'tool:search' => 'open',
    ]);
});
