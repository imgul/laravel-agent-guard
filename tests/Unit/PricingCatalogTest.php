<?php

declare(strict_types=1);

use Imgul\AgentGuard\Support\Pricing\PricingCatalog;
use Imgul\AgentGuard\Support\TextUsage;

function catalog(): PricingCatalog
{
    return PricingCatalog::fromFile(__DIR__.'/../../src/Support/Pricing/prices.json');
}

it('loads the versioned catalog', function (): void {
    $catalog = catalog();

    expect($catalog->version())->toBe('2026-11-24.1');
    expect($catalog->has('claude-haiku-4-5'))->toBeTrue();
});

it('computes USD cost from usage and model prices', function (): void {
    $catalog = catalog();

    // claude-haiku-4-5: $0.80/1k input, $4.00/1k output.
    $usage = new TextUsage(inputTokens: 1000, outputTokens: 1000);

    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(4.80);
});

it('accounts for cache and reasoning tokens', function (): void {
    $catalog = catalog();

    // claude-haiku-4-5: input 0.80, output 4.00, cache_read 0.08, reasoning 4.00.
    $usage = new TextUsage(inputTokens: 1000, outputTokens: 1000, cacheReadInputTokens: 1000, reasoningTokens: 500);

    // 1.0*0.8 + 1.0*4.0 + 1.0*0.08 + 0.5*4.0 = 0.8 + 4.0 + 0.08 + 2.0
    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(6.88);
});

it('returns null USD for unknown models (token budgets still enforced)', function (): void {
    $catalog = catalog();

    expect($catalog->has('no-such-model'))->toBeFalse();
    expect($catalog->usdFor(new TextUsage(1000, 1000), 'no-such-model'))->toBeNull();
});

it('applies runtime overrides over the catalog', function (): void {
    $catalog = PricingCatalog::fromFile(
        __DIR__.'/../../src/Support/Pricing/prices.json',
        ['claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0]],
    );

    $usage = new TextUsage(inputTokens: 1000, outputTokens: 1000);

    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(6.0);
});

it('allows mutating overrides at runtime', function (): void {
    $catalog = catalog();
    $catalog->override('claude-haiku-4-5', ['input' => 2.0, 'output' => 10.0]);

    $usage = new TextUsage(inputTokens: 1000, outputTokens: 1000);

    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(12.0);
});

it('builds from a decoded array via fromArray', function (): void {
    $data = json_decode((string) file_get_contents(__DIR__.'/../../src/Support/Pricing/prices.json'), true);

    $catalog = PricingCatalog::fromArray($data);

    expect($catalog->version())->toBe('2026-11-24.1')
        ->and($catalog->has('gpt-5'))->toBeTrue();
});
