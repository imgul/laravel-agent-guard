<?php

declare(strict_types=1);

use Imgul\AgentGuard\Support\TextUsage;

it('maps serialized usage payloads', function (): void {
    $usage = TextUsage::fromArray([
        'input_tokens' => 1200,
        'output_tokens' => 300,
        'cache_read_input_tokens' => 800,
        'cache_write_input_tokens' => 100,
        'reasoning_tokens' => 120,
    ]);

    expect($usage->inputTokens)->toBe(1200)
        ->and($usage->outputTokens)->toBe(300)
        ->and($usage->cacheReadInputTokens)->toBe(800)
        ->and($usage->cacheWriteInputTokens)->toBe(100)
        ->and($usage->reasoningTokens)->toBe(120);
});

it('preserves null for unreported cache fields', function (): void {
    $usage = TextUsage::fromArray(['input_tokens' => 10, 'output_tokens' => 5]);

    expect($usage->cacheReadInputTokens)->toBeNull()
        ->and($usage->cacheWriteInputTokens)->toBeNull()
        ->and($usage->reasoningTokens)->toBeNull();
});

it('sums two usages preserving null semantics', function (): void {
    $a = new TextUsage(100, 50, 40, 10, 20);
    $b = new TextUsage(200, 60);

    $sum = $a->add($b);

    expect($sum->inputTokens)->toBe(300)
        ->and($sum->outputTokens)->toBe(110)
        ->and($sum->cacheReadInputTokens)->toBe(40)
        ->and($sum->cacheWriteInputTokens)->toBe(10)
        ->and($sum->reasoningTokens)->toBe(20);
});

it('reports total tokens and emptiness', function (): void {
    expect((new TextUsage(1000, 500))->totalTokens())->toBe(1500);
    expect((new TextUsage)->isEmpty())->toBeTrue();
    expect((new TextUsage(1, 0))->isEmpty())->toBeFalse();
});

it('round-trips through toArray', function (): void {
    $usage = new TextUsage(100, 50, 40, 10, 20);

    expect(TextUsage::fromArray($usage->toArray()))
        ->toEqual($usage);
});
