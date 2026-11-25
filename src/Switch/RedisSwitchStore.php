<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Switch;

use Redis;

/**
 * Redis-backed switch store.
 *
 * Writes kill-switch and circuit-breaker state to Redis so the state is
 * shared across processes — a `agent-guard:kill` in a CLI process is
 * visible to Horizon workers, Octane, and web requests.
 */
final class RedisSwitchStore implements SwitchStore
{
    public function __construct(private readonly Redis $redis) {}

    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);

        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        if ($ttl > 0) {
            $this->redis->setex($key, $ttl, (string) $value);
        } else {
            $this->redis->set($key, (string) $value);
        }
    }

    public function delete(string $key): bool
    {
        return (bool) $this->redis->del($key);
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($key) > 0;
    }
}
