<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

use Imgul\AgentGuard\Events\CircuitTripped;
use Imgul\AgentGuard\Switch\InMemorySwitchStore;
use Imgul\AgentGuard\Switch\SwitchStore;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Circuit breaker: CLOSED -> OPEN -> HALF_OPEN -> CLOSED.
 *
 * State is persisted through a SwitchStore so the breaker survives across
 * processes. The breaker is keyed per-run (using a run id), so a loop in
 * one run does not block a later, independent run for the same tenant.
 *
 * Lifecycle:
 *  1. trip() moves CLOSED/HALF_OPEN -> OPEN, records the open time.
 *  2. After the cooldown, allowProbe() transitions OPEN -> HALF_OPEN and
 *     returns true so the caller lets one probe step through.
 *  3. probeResult($key, $success) closes (CLOSED) on success, or
 *     re-trips (OPEN) on failure with a fresh cooldown.
 *  4. reset() clears the state for a run.
 *
 * On HALF_OPEN the caller should prune accumulated context before retrying
 * the probe step, so the probe itself cannot re-bloat the context.
 */
final class CircuitBreaker
{
    private readonly SwitchStore $store;

    public function __construct(
        ?SwitchStore $store = null,
        private readonly int $cooldownSeconds = 60,
        private readonly bool $halfOpenProbe = true,
        private readonly ?Dispatcher $dispatcher = null,
    ) {
        $this->store = $store ?? new InMemorySwitchStore;
    }

    /**
     * Current state for a scoped key (defaults to CLOSED).
     */
    public function state(string $key): LoopState
    {
        $data = $this->load($key);

        $raw = $data['state'] ?? null;

        return $raw === null ? LoopState::Closed : LoopState::from((string) $raw);
    }

    /**
     * Whether the breaker is open (fail-closed) for a scoped key.
     */
    public function isOpen(string $key): bool
    {
        return $this->state($key) === LoopState::Open;
    }

    /**
     * Trip the breaker for a scoped key: CLOSED/HALF_OPEN -> OPEN.
     *
     * @return LoopState the resulting state
     */
    public function trip(string $key): LoopState
    {
        $current = $this->state($key);

        if ($current === LoopState::Open) {
            return $current;
        }

        $this->save($key, LoopState::Open);

        $this->dispatch(new CircuitTripped($key, LoopState::Open, $this->cooldownSeconds));

        return LoopState::Open;
    }

    /**
     * Attempt a HALF_OPEN probe for an open breaker after the cooldown.
     *
     * Returns true when the probe is allowed (state transitioned to
     * HALF_OPEN) — the caller should then prune accumulated context before
     * retrying, via shouldPruneContext().
     */
    public function allowProbe(string $key): bool
    {
        $data = $this->load($key);

        $rawState = $data['state'] ?? null;

        if ($rawState === null || $rawState !== LoopState::Open->value) {
            return false;
        }

        if (! $this->halfOpenProbe) {
            return false;
        }

        $openedAt = $data['openedAt'] ?? 0.0;

        if ((microtime(true) - $openedAt) < $this->cooldownSeconds) {
            return false;
        }

        $this->save($key, LoopState::HalfOpen, $openedAt);

        $this->dispatch(new CircuitTripped($key, LoopState::HalfOpen, 0));

        return true;
    }

    /**
     * Whether the caller should prune accumulated context before a probe
     * retry (true only while the breaker is HALF_OPEN).
     */
    public function shouldPruneContext(string $key): bool
    {
        return $this->state($key) === LoopState::HalfOpen;
    }

    /**
     * Record a HALF_OPEN probe result: success resets to CLOSED (window
     * reset), failure re-trips to OPEN with a fresh cooldown.
     *
     * @return LoopState the resulting state
     */
    public function probeResult(string $key, bool $success): LoopState
    {
        $current = $this->state($key);

        if ($current !== LoopState::HalfOpen) {
            return $current;
        }

        if ($success) {
            $this->store->delete($this->storageKey($key));

            return LoopState::Closed;
        }

        $this->save($key, LoopState::Open);

        $this->dispatch(new CircuitTripped($key, LoopState::Open, $this->cooldownSeconds));

        return LoopState::Open;
    }

    /**
     * Reset a scoped breaker to CLOSED (also used to clear stale state).
     */
    public function reset(string $key): void
    {
        $this->store->delete($this->storageKey($key));
    }

    /**
     * Snapshot of all scoped breaker states.
     *
     * @return array<string, string>  key => state value
     */
    public function snapshot(): array
    {
        $prefix = $this->storageKey('');
        $snapshot = [];

        foreach ($this->store->keys($prefix) as $storedKey) {
            // Trim the prefix to recover the scoped key.
            $key = substr($storedKey, strlen($prefix));

            if ($key === '') {
                continue;
            }

            $data = $this->load($key);
            $raw = $data['state'] ?? null;

            if ($raw !== null) {
                $snapshot[$key] = (string) $raw;
            }
        }

        return $snapshot;
    }

    /**
     * Load and decode the stored state for a key.
     *
     * @return array{state?: string, openedAt?: float}
     */
    private function load(string $key): array
    {
        $raw = $this->store->get($this->storageKey($key));

        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $data = [];

                if (isset($decoded['state']) && is_string($decoded['state'])) {
                    $data['state'] = $decoded['state'];
                }

                if (isset($decoded['openedAt']) && is_numeric($decoded['openedAt'])) {
                    $data['openedAt'] = (float) $decoded['openedAt'];
                }

                return $data;
            }
        }

        return [];
    }

    private function save(string $key, LoopState $state, ?float $openedAt = null): void
    {
        $data = ['state' => $state->value, 'openedAt' => $openedAt ?? microtime(true)];

        $this->store->set($this->storageKey($key), json_encode($data) ?: '');
    }

    private function storageKey(string $key): string
    {
        return 'agent-guard:breaker:'.$key;
    }

    private function dispatch(CircuitTripped $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
