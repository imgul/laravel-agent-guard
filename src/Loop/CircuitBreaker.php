<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

use Imgul\AgentGuard\Events\CircuitTripped;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Circuit breaker: CLOSED -> OPEN -> HALF_OPEN -> CLOSED.
 *
 * One breaker instance may serve many scoped keys (a run id or a
 * scope:tool pair). State transitions emit CircuitTripped. On HALF_OPEN the
 * caller should prune accumulated context before retrying the probe step, so
 * the probe itself cannot re-bloat the context.
 */
final class CircuitBreaker
{
    /**
     * @var array<string, array{state: LoopState, openedAt: float}>
     */
    private array $states = [];

    public function __construct(
        private readonly int $cooldownSeconds = 60,
        private readonly bool $halfOpenProbe = true,
        private readonly ?Dispatcher $dispatcher = null,
    ) {}

    /**
     * Current state for a scoped key (defaults to CLOSED).
     */
    public function state(string $key): LoopState
    {
        return $this->states[$key]['state'] ?? LoopState::Closed;
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

        $this->states[$key] = ['state' => LoopState::Open, 'openedAt' => microtime(true)];

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
        $state = $this->states[$key] ?? null;

        if ($state === null || $state['state'] !== LoopState::Open) {
            return false;
        }

        if (! $this->halfOpenProbe) {
            return false;
        }

        if ((microtime(true) - $state['openedAt']) < $this->cooldownSeconds) {
            return false;
        }

        $this->states[$key]['state'] = LoopState::HalfOpen;

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
            unset($this->states[$key]);

            return LoopState::Closed;
        }

        $this->states[$key] = ['state' => LoopState::Open, 'openedAt' => microtime(true)];

        $this->dispatch(new CircuitTripped($key, LoopState::Open, $this->cooldownSeconds));

        return LoopState::Open;
    }

    /**
     * Reset a scoped breaker to CLOSED (also used to clear stale state).
     */
    public function reset(string $key): void
    {
        unset($this->states[$key]);
    }

    /**
     * Snapshot of all scoped breaker states.
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->states as $key => $state) {
            $snapshot[$key] = $state['state']->value;
        }

        return $snapshot;
    }

    private function dispatch(CircuitTripped $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
