<?php

declare(strict_types=1);

namespace Imgul\AgentGuard;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Imgul\AgentGuard\Budget\Drivers\DatabaseLedger;
use Imgul\AgentGuard\Budget\Drivers\RedisLedger;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Pricing\PricingCatalog;
use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;
use Imgul\AgentGuard\Support\Similarity\SimHash;
use Imgul\AgentGuard\Support\Similarity\TrigramJaccard;

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

            return new AgentGuard($config->get('agent-guard', []));
        });

        $this->app->alias(AgentGuard::class, 'agent-guard');

        $this->app->singleton(Ledger::class, function (Container $container): Ledger {
            /** @var Repository $config */
            $config = $container->make('config');

            if ($config->get('agent-guard.driver') === 'database') {
                return new DatabaseLedger();
            }

            return new RedisLedger($container->make('redis')->connection());
        });

        $this->app->singleton(PricingCatalog::class, function (Container $container): PricingCatalog {
            /** @var Repository $config */
            $config = $container->make('config');

            $path = (string) $config->get(
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
                defaultOutputTokens: (int) $config->get('agent-guard.estimate.output_tokens', 256),
            );
        });

        $this->app->singleton(GuardRuntime::class, function (Container $container): GuardRuntime {
            /** @var Repository $config */
            $config = $container->make('config');

            return new GuardRuntime(
                ledger: $container->make(Ledger::class),
                estimator: $container->make(CostEstimator::class),
                similarity: $this->resolveSimilarity($config),
                dispatcher: $container->make('events'),
                enforce: (string) $config->get('agent-guard.mode') === 'enforce',
                killSwitchEnabled: (bool) $config->get('agent-guard.kill_switch', true),
                loopCooldownSeconds: (int) $config->get('agent-guard.loop.breaker.cooldown_seconds', 60),
                loopWindowSize: (int) $config->get('agent-guard.loop.window_size', 50),
                loopMaxRepeats: (int) $config->get('agent-guard.loop.max_repeats', 3),
                loopMaxStepGrowthRatio: (float) $config->get('agent-guard.loop.max_step_growth_ratio', 2.5),
            );
        });
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
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
        $strategy = (string) $config->get('agent-guard.loop.similarity', 'simhash');

        return match ($strategy) {
            'none' => null,
            'simhash' => new SimHash(),
            'trigram' => new TrigramJaccard(),
            default => $this->app->make($strategy),
        };
    }
}
