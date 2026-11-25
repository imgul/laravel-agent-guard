<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Switch;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed switch store.
 *
 * Uses Laravel's cache so the database driver (and any cache driver)
 * works for cross-process switch state. The default cache TTL for
 * kill-switch entries is 7 days — long enough for incident response,
 * short enough to avoid stale state forever.
 */
final class DatabaseSwitchStore implements SwitchStore
{
    public function __construct(
        private readonly string $storeName = 'agent-guard-switches',
        private readonly int $defaultTtl = 604800,
    ) {}

    public function get(string $key): mixed
    {
        return Cache::store($this->storeName)->get($key);
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        Cache::store($this->storeName)->put(
            $key,
            $value,
            $ttl > 0 ? $ttl : $this->defaultTtl,
        );
    }

    public function delete(string $key): bool
    {
        return Cache::store($this->storeName)->forget($key);
    }

    public function has(string $key): bool
    {
        return Cache::store($this->storeName)->has($key);
    }
}
