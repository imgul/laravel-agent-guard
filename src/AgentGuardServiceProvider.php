<?php

declare(strict_types=1);

namespace Imgul\AgentGuard;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Imgul\AgentGuard\Budget\Drivers\DatabaseLedger;
use Imgul\AgentGuard\Budget\Drivers\RedisLedger;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Console\AgentGuardBudgetsCommand;
use Imgul\AgentGuard\Console\AgentGuardKillCommand;
use Imgul\AgentGuard\Console\AgentGuardStatusCommand;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Pricing\PricingCatalog;
use Imgul\AgentGuard\Support\Similarity\SimHash;
use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;
use Imgul\AgentGuard\Support\Similarity\TrigramJaccard;
use Imgul\AgentGuard\Switch\DatabaseSwitchStore;
use Imgul\AgentGuard\Switch\RedisSwitchStore;
use Imgul\AgentGuard\Switch\SwitchStore;

final class AgentGuardServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/agent-guard.php',
            'agent-guard',
        );

        $this->app->singleton(AgentGuard::class, function (Container $container): AgentGuard {
            /** @var Repository $config */
            $config = $container->make('config');

            /** @var array<string, mixed> $agentGuardConfig */
            $agentGuardConfig = $config->get('agent-guard', []);

            return new AgentGuard($agentGuardConfig);
        });

        $this->app->alias(AgentGuard::class, 'agent-guard');

        $this->app->singleton(Ledger::class, function (Container $container): Ledger {
            /** @var Repository $config */
            $config = $container->make('config');

            if ($config->get('agent-guard.driver') === 'database') {
                return new DatabaseLedger;
            }

            /** @var \Redis $client */
            $client = $container->make('redis')->connection()->client();

            return new RedisLedger($client);
        });

        $this->app->singleton(PricingCatalog::class, function (Container $container): PricingCatalog {
            /** @var Repository $config */
            $config = $container->make('config');

            $path = self::stringConfig(
                $config,
                'agent-guard.pricing.catalog',
                __DIR__.'/../src/Support/Pricing/prices.json',
            );

            return PricingCatalog::fromFile($path);
        });

        $this->app->singleton(CostEstimator::class, function (Container $container): CostEstimator {
            /** @var Repository $config */
            $config = $container->make('config');

            return new CostEstimator(
                pricing: $container->make(PricingCatalog::class),
                defaultOutputTokens: self::intConfig($config, 'agent-guard.estimate.output_tokens', 256),
            );
        });

        $this->app->singleton(SwitchStore::class, function (Container $container): SwitchStore {
            /** @var Repository $config */
            $config = $container->make('config');

            if ($config->get('agent-guard.driver') === 'database') {
                return new DatabaseSwitchStore;
            }

            /** @var \Redis $client */
            $client = $container->make('redis')->connection()->client();

            return new RedisSwitchStore($client);
        });

        $this->app->singleton(GuardRuntime::class, function (Container $container): GuardRuntime {
            /** @var Repository $config */
            $config = $container->make('config');

            return new GuardRuntime(
                ledger: $container->make(Ledger::class),
                estimator: $container->make(CostEstimator::class),
                similarity: $this->resolveSimilarity($config),
                dispatcher: $container->make('events'),
                enforce: self::stringConfig($config, 'agent-guard.mode', 'observe') === 'enforce',
                killSwitchEnabled: self::boolConfig($config, 'agent-guard.kill_switch', true),
                loopCooldownSeconds: self::intConfig($config, 'agent-guard.loop.breaker.cooldown_seconds', 60),
                loopWindowSize: self::intConfig($config, 'agent-guard.loop.window_size', 50),
                loopMaxRepeats: self::intConfig($config, 'agent-guard.loop.max_repeats', 3),
                loopMaxStepGrowthRatio: self::floatConfig($config, 'agent-guard.loop.max_step_growth_ratio', 2.5),
                switchStore: $container->make(SwitchStore::class),
            );
        });
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AgentGuardStatusCommand::class,
                AgentGuardKillCommand::class,
                AgentGuardBudgetsCommand::class,
            ]);

            $this->publishes(
                [__DIR__.'/../config/agent-guard.php' => config_path('agent-guard.php')],
                'agent-guard-config',
            );
        }
    }

    /**
     * Resolve the configured similarity strategy into a concrete instance.
     */
    private function resolveSimilarity(Repository $config): ?SimilarityStrategy
    {
        $strategy = self::stringConfig($config, 'agent-guard.loop.similarity', 'simhash');

        if ($strategy === 'none') {
            return null;
        }

        if ($strategy === 'simhash') {
            return new SimHash;
        }

        if ($strategy === 'trigram') {
            return new TrigramJaccard;
        }

        $resolved = $this->app->make($strategy);

        return $resolved instanceof SimilarityStrategy ? $resolved : null;
    }

    /**
     * Read a string config value with a typed fallback (avoids casting mixed).
     */
    private static function stringConfig(Repository $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Read an int config value with a typed fallback.
     */
    private static function intConfig(Repository $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_int($value) ? $value : $default;
    }

    /**
     * Read a float config value with a typed fallback (accepts int or float).
     */
    private static function floatConfig(Repository $config, string $key, float $default): float
    {
        $value = $config->get($key, $default);

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /**
     * Read a bool config value with a typed fallback.
     */
    private static function boolConfig(Repository $config, string $key, bool $default): bool
    {
        $value = $config->get($key, $default);

        return is_bool($value) ? $value : $default;
    }
}
