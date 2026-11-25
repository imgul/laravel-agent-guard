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

    expect($catalog->version())->toBe('2026-11-25.1');
    expect($catalog->has('claude-haiku-4-5'))->toBeTrue();
});

it('computes USD cost from usage and model prices', function (): void {
    $catalog = catalog();

    // claude-haiku-4-5: $0.80/1M input, $4.00/1M output.
    // 1k in + 1k out = 0.001 * 0.8 + 0.001 * 4.0 = $0.0048
    $usage = new TextUsage(inputTokens: 1000, outputTokens: 1000);

    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(0.0048);
});

it('accounts for cache and reasoning tokens', function (): void {
    $catalog = catalog();

    // claude-haiku-4-5: input 0.80/1M, output 4.00/1M,
    // cache_read 0.08/1M, reasoning 4.00/1M.
    $usage = new TextUsage(inputTokens: 1000, outputTokens: 1000, cacheReadInputTokens: 1000, reasoningTokens: 500);

    // 0.001*0.8 + 0.001*4.0 + 0.001*0.08 + 0.0005*4.0
    // = 0.0008 + 0.004 + 0.00008 + 0.002 = $0.00688
    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(0.00688);
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

    // 0.001*1.0 + 0.001*5.0 = $0.006
    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(0.006);
});

it('allows mutating overrides at runtime', function (): void {
    $catalog = catalog();
    $catalog->override('claude-haiku-4-5', ['input' => 2.0, 'output' => 10.0]);

    $usage = new TextUsage(inputTokens: 1000, outputTokens: 1000);

    // 0.001*2.0 + 0.001*10.0 = $0.012
    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(0.012);
});

it('builds from a decoded array via fromArray', function (): void {
    /** @var array<string, mixed> $data */
    $data = json_decode((string) file_get_contents(__DIR__.'/../../src/Support/Pricing/prices.json'), true);

    $catalog = PricingCatalog::fromArray($data);

    expect($catalog->version())->toBe('2026-11-25.1')
        ->and($catalog->has('gpt-5'))->toBeTrue();
});

it('uses per-1M-token prices, not per-1k', function (): void {
    $catalog = catalog();

    // If prices were per-1k, 1M tokens would cost $800 for haiku input.
    // At per-1M, 1M input tokens cost exactly $0.80.
    $usage = new TextUsage(inputTokens: 1_000_000, outputTokens: 0);

    expect($catalog->usdFor($usage, 'claude-haiku-4-5'))->toBe(0.80);
});
