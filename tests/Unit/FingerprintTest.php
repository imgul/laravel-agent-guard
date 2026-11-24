<?php

declare(strict_types=1);

use Imgul\AgentGuard\Support\Fingerprint;

it('produces stable exact fingerprints regardless of key order', function (): void {
    $a = Fingerprint::exact('search', ['q' => 'hello', 'limit' => 10]);
    $b = Fingerprint::exact('search', ['limit' => 10, 'q' => 'hello']);

    expect($a)->toBe($b);
});

it('produces different exact fingerprints for different arguments', function (): void {
    expect(Fingerprint::exact('search', ['q' => 'a']))
        ->not->toBe(Fingerprint::exact('search', ['q' => 'b']))
        ->not->toBe(Fingerprint::exact('fetch', ['q' => 'a']));
});

it('normalizes cosmetic argument variation', function (): void {
    // Whitespace, numeric/pagination params, and argument order are cosmetic.
    $a = Fingerprint::normalized('search', ['q' => 'hello world', 'page' => 1]);
    $b = Fingerprint::normalized('search', ['page' => 999, 'q' => 'hello   world']);
    $c = Fingerprint::normalized('search', ['q' => 'hello world', 'offset' => 500]);

    expect($a)->toBe($b)->toBe($c);
});

it('keeps semantically meaningful arguments distinct after normalization', function (): void {
    $a = Fingerprint::normalized('search', ['q' => 'laravel guards']);
    $b = Fingerprint::normalized('search', ['q' => 'laravel budgets']);

    expect($a)->not->toBe($b);
});

it('normalizes nested numeric arguments', function (): void {
    $a = Fingerprint::normalized('fetch', ['filter' => ['id' => 7, 'tag' => 'x']]);
    $b = Fingerprint::normalized('fetch', ['filter' => ['id' => 99, 'tag' => 'x']]);

    expect($a)->toBe($b);
});
