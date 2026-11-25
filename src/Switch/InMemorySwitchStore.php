<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Switch;

/**
 * In-memory switch store for tests and the fake runtime.
 *
 * State lives only for the lifetime of the process — no Redis, no
 * database. Used by `AgentGuardFake` and unit tests that need
 * deterministic, side-effect-free switch state.
 */
final class InMemorySwitchStore implements SwitchStore
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $store = [];

    public function get(string $key): mixed
    {
        if (! isset($this->store[$key])) {
            return null;
        }

        if ($this->store[$key]['expiresAt'] !== null && $this->store[$key]['expiresAt'] < time()) {
            unset($this->store[$key]);

            return null;
        }

        return $this->store[$key]['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => $ttl > 0 ? time() + $ttl : null,
        ];
    }

    public function delete(string $key): bool
    {
        if (! isset($this->store[$key])) {
            return false;
        }

        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}
