<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Tests;

use Imgul\AgentGuard\AgentGuardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  array<mixed>  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AgentGuardServiceProvider::class,
        ];
    }
}
