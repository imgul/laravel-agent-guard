<?php

declare(strict_types=1);

namespace Imgul\AgentGuard;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Imgul\AgentGuard\Budget\Drivers\DatabaseLedger;
use Imgul\AgentGuard\Budget\Drivers\RedisLedger;
use Imgul\AgentGuard\Budget\Ledger;

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
}
