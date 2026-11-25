<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support\Pricing;

use Imgul\AgentGuard\Support\TextUsage;
use RuntimeException;

/**
 * Versioned pricing catalog.
 *
 * Prices are data, never hardcoded in release tags (PRD FR-2.5): the catalog
 * is a versioned JSON file synced by a scheduled workflow, with runtime
 * overrides taking precedence so custom/negotiated prices work without
 * waiting for a sync.
 *
 * A missing price for a model means token budgets are still enforced while
 * USD is reported as null (documented behavior).
 */
final class PricingCatalog
{
    /**
     * @param  array<string, array<string, float>>  $models  model -> price components (per 1k tokens)
     * @param  array<string, array<string, float>>  $overrides  runtime/DB overrides (same shape)
     */
    public function __construct(
        public readonly string $version,
        private readonly array $models,
        private array $overrides = [],
    ) {}

    /**
     * Load the catalog from a JSON file.
     *
     * @param  array<string, array<string, float>>  $overrides  optional runtime/DB overrides keyed by model
     */
    public static function fromFile(string $path, array $overrides = []): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Pricing catalog not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new RuntimeException("Invalid pricing catalog JSON at: {$path}");
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data, $overrides);
    }

    /**
     * Build the catalog from an already-decoded array.
     *
     * @param  array<string, mixed>  $data  parsed catalog JSON ({version, models})
     * @param  array<string, array<string, float>>  $overrides  runtime/DB overrides
     */
    public static function fromArray(array $data, array $overrides = []): self
    {
        if (! isset($data['models'], $data['version'])) {
            throw new RuntimeException('Invalid pricing catalog: missing version or models.');
        }

        if (! is_array($data['models'])) {
            throw new RuntimeException('Invalid pricing catalog: models must be an array.');
        }

        $models = [];

        foreach ($data['models'] as $model => $prices) {
            if (is_array($prices)) {
                $models[(string) $model] = self::normalizePrices($prices);
            }
        }

        $version = $data['version'];

        return new self(
            version: is_string($version) ? $version : '',
            models: $models,
            overrides: self::normalizeOverrides($overrides),
        );
    }

    /**
     * The catalog version string.
     */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * Whether a price is known for the given model.
     */
    public function has(string $model): bool
    {
        return $this->resolved($model) !== null;
    }

    /**
     * Get the price components for a model, merged with overrides.
     *
     * @return array<string, float>|null
     */
    public function resolve(string $model): ?array
    {
        return $this->resolved($model);
    }

    /**
     * Compute the USD cost of a usage against a model's price.
     *
     * Returns null when the model has no price (token budgets still enforced).
     */
    public function usdFor(TextUsage $usage, string $model): ?float
    {
        $price = $this->resolved($model);

        if ($price === null) {
            return null;
        }

        $cost = 0.0;

        $cost += ($usage->inputTokens / 1000.0) * ($price['input'] ?? 0.0);
        $cost += ($usage->outputTokens / 1000.0) * ($price['output'] ?? 0.0);

        if ($usage->cacheReadInputTokens !== null) {
            $cost += ($usage->cacheReadInputTokens / 1000.0) * ($price['cache_read'] ?? 0.0);
        }

        if ($usage->cacheWriteInputTokens !== null) {
            $cost += ($usage->cacheWriteInputTokens / 1000.0) * ($price['cache_write'] ?? 0.0);
        }

        if ($usage->reasoningTokens !== null) {
            $cost += ($usage->reasoningTokens / 1000.0) * ($price['reasoning'] ?? 0.0);
        }

        return round($cost, 6);
    }

    /**
     * Add or replace a runtime override for a model.
     *
     * @param  array<string, float>  $prices
     */
    public function override(string $model, array $prices): void
    {
        $this->overrides[$model] = self::normalizePrices($prices);
    }

    /**
     * @return array<string, float>|null
     */
    private function resolved(string $model): ?array
    {
        if (isset($this->overrides[$model])) {
            return $this->overrides[$model];
        }

        return $this->models[$model] ?? null;
    }

    /**
     * @param  array<mixed, mixed>  $prices
     * @return array<string, float>
     */
    private static function normalizePrices(array $prices): array
    {
        $normalized = [];

        foreach (['input', 'output', 'cache_read', 'cache_write', 'reasoning'] as $key) {
            if (isset($prices[$key]) && is_numeric($prices[$key])) {
                $normalized[$key] = (float) $prices[$key];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, float>>
     */
    private static function normalizeOverrides(array $overrides): array
    {
        $normalized = [];

        foreach ($overrides as $model => $prices) {
            if (is_array($prices)) {
                $normalized[(string) $model] = self::normalizePrices($prices);
            }
        }

        return $normalized;
    }
}
