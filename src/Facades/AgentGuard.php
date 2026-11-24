<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string mode()
 * @method static bool isObserving()
 * @method static bool isEnforcing()
 * @method static string driver()
 * @method static mixed config(string $key, mixed $default = null)
 *
 * @see \Imgul\AgentGuard\AgentGuard
 */
final class AgentGuard extends Facade
{
    /**
     * Get the registered name of the component in the service container.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'agent-guard';
    }
}
