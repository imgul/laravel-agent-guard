<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

/**
 * Circuit-breaker state machine state.
 *
 * CLOSED      — normal monitoring.
 * OPEN        — breaker tripped; fail-closed denial for the scoped run/tool.
 * HALF_OPEN   — cooldown elapsed; one probe step is allowed (with accumulated
 *               context pruned before the retry so the probe cannot re-bloat).
 */
enum LoopState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';

    /**
     * Whether this state permits guarded execution to proceed.
     */
    public function allowsExecution(): bool
    {
        return $this === self::Closed || $this === self::HalfOpen;
    }
}
