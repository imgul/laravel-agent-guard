<?php

declare(strict_types=1);

namespace Imgul\AgentGuard;

use function Illuminate\Support\data_get;

/**
 * Core runtime container for laravel-agent-guard.
 *
 * Holds the merged configuration and the global mode. The loop, budget, and
 * downshift engines are attached to this container as development proceeds
 * (see PLAN phases 1-3); the facade and middleware always resolve this
 * single instance, so wiring is centralized in one place.
 */
final class AgentGuard
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * The active mode: "observe" (events only, no enforcement) or "enforce".
     */
    public function mode(): string
    {
        return (string) ($this->config['mode'] ?? 'observe');
    }

    /**
     * Whether the guard is running in observe mode (detection + events only).
     */
    public function isObserving(): bool
    {
        return $this->mode() === 'observe';
    }

    /**
     * Whether the guard is running in enforce mode (action ladder active).
     */
    public function isEnforcing(): bool
    {
        return $this->mode() === 'enforce';
    }

    /**
     * The configured ledger driver ("redis" or "database").
     */
    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'redis');
    }

    /**
     * Read a configuration value using dot-notation.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }
}
