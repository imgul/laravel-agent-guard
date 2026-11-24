<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

interface DetectorInterface
{
    /**
     * Run this detector against the current state. Returns a finding when the
     * detector's condition is met, otherwise null.
     */
    public function detect(ToolCall $call, DetectorContext $context): ?LoopFinding;
}
