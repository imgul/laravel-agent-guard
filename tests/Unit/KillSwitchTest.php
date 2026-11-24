<?php

declare(strict_types=1);

use Imgul\AgentGuard\Loop\KillSwitch;

it('starts with all switches closed', function (): void {
    $switch = new KillSwitch();

    expect($switch->isOpen('run', 'run-1'))->toBeFalse();
    expect($switch->isOpen('tenant', 'tenant-1'))->toBeFalse();
    expect($switch->anyOpen())->toBeFalse();
});

it('opens and releases switches per scope and key', function (): void {
    $switch = new KillSwitch();

    expect($switch->kill('tenant', 'tenant-1'))->toBeTrue();
    expect($switch->isOpen('tenant', 'tenant-1'))->toBeTrue();
    expect($switch->anyOpen())->toBeTrue();

    expect($switch->unkill('tenant', 'tenant-1'))->toBeTrue();
    expect($switch->isOpen('tenant', 'tenant-1'))->toBeFalse();
    expect($switch->anyOpen())->toBeFalse();
});

it('scopes switches independently', function (): void {
    $switch = new KillSwitch();
    $switch->kill('run', 'run-1');

    expect($switch->isOpen('run', 'run-1'))->toBeTrue();
    expect($switch->isOpen('run', 'run-2'))->toBeFalse();
    expect($switch->isOpen('tenant', 'tenant-1'))->toBeFalse();
});

it('does not re-dispatch when a switch is toggled to its current state', function (): void {
    $switch = new KillSwitch();

    expect($switch->kill('run', 'run-1'))->toBeTrue();
    expect($switch->kill('run', 'run-1'))->toBeFalse();
    expect($switch->unkill('run', 'run-1'))->toBeTrue();
    expect($switch->unkill('run', 'run-1'))->toBeFalse();
});

it('exposes open scopes for status reporting', function (): void {
    $switch = new KillSwitch();
    $switch->kill('run', 'run-1');
    $switch->kill('run', 'run-2');
    $switch->kill('tenant', 'tenant-1');

    expect($switch->openScopes())->toBe([
        'run' => ['run-1', 'run-2'],
        'tenant' => ['tenant-1'],
    ]);
});
