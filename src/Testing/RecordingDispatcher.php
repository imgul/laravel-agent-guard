<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Testing;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;

/**
 * An event dispatcher that records every dispatched event while delegating
 * delivery to the real application bus.
 *
 * Used by AgentGuardFake so assertions can inspect guard decisions
 * (loop / budget / kill / downshift) without losing the real bus's
 * listeners — app-level event handlers still fire, only the ledger is
 * swapped to in-memory.
 *
 * The guard components only ever call `dispatch()`, so this override
 * records and forwards; the inherited listener/subscribe methods operate
 * on this instance's own (unused) registry.
 */
final class RecordingDispatcher extends EventDispatcher
{
    /**
     * @var list<object|string>
     */
    private array $recorded = [];

    public function __construct(
        private readonly Dispatcher $inner,
    ) {
        // Construct the concrete dispatcher with the application container.
        parent::__construct(app());
    }

    /**
     * Record the event, then deliver it through the real bus.
     *
     * @param  array<int, mixed>  $payload
     * @param  bool  $handleQueued
     * @return mixed
     */
    public function dispatch($event, $payload = [], $handleQueued = true)
    {
        $this->recorded[] = $event;

        return $this->inner->dispatch($event, $payload, $handleQueued);
    }

    /**
     * All recorded events, in dispatch order.
     *
     * @return list<object|string>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Whether a specific event class was recorded.
     */
    public function recordedEvent(string $eventClass): bool
    {
        foreach ($this->recorded as $event) {
            if ($event instanceof $eventClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first recorded event matching the given class.
     */
    public function firstRecorded(string $eventClass): ?object
    {
        foreach ($this->recorded as $event) {
            if ($event instanceof $eventClass) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The first recorded event matching the class and the predicate.
     */
    public function recordedMatching(string $eventClass, callable $predicate): ?object
    {
        foreach ($this->recorded as $event) {
            if ($event instanceof $eventClass && $predicate($event)) {
                return $event;
            }
        }

        return null;
    }
}
