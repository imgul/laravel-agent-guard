<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Switch;

/**
 * Persistent storage for kill-switch and circuit-breaker state.
 *
 * The kill switch and circuit breaker must survive across processes — a
 * `php artisan agent-guard:kill` runs in its own process and must affect
 * Horizon workers, Octane, and web requests. This interface abstracts the
 * storage backend so the real drivers (Redis, database) and the test fake
 * (in-memory) share the same contract.
 */
interface SwitchStore
{
    /**
     * Get a stored value, or null when absent.
     *
     * @return mixed
     */
    public function get(string $key);

    /**
     * Store a value with an optional TTL (seconds). Zero means no expiry.
     */
    public function set(string $key, mixed $value, int $ttl = 0): void;

    /**
     * Remove a stored value. Returns true when the key existed.
     */
    public function delete(string $key): bool;

    /**
     * Whether the key exists in storage.
     */
    public function has(string $key): bool;

    /**
     * Return all keys matching the given prefix. Used for enumeration
     * (e.g. listing open kill-switch scopes for the status command).
     *
     * @return list<string>
     */
    public function keys(string $prefix): array;
}
