<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop\Detectors;

use Imgul\AgentGuard\Loop\DetectorContext;
use Imgul\AgentGuard\Loop\DetectorInterface;
use Imgul\AgentGuard\Loop\LoopFinding;
use Imgul\AgentGuard\Loop\ToolCall;

/**
 * FR-1.3: raw call-rate spike detection per tool within the window.
 *
 * Trips when a single tool has been called at least frequencyThreshold
 * times in the sliding window, regardless of argument variation.
 */
final class FrequencyDetector implements DetectorInterface
{
    public function detect(ToolCall $call, DetectorContext $context): ?LoopFinding
    {
        if ($context->isExempt($call->tool)) {
            return null;
        }

        $count = 1; // include the current call

        foreach ($context->window as $entry) {
            if ($entry->tool === $call->tool) {
                $count++;
            }
        }

        if ($count < $context->frequencyThreshold) {
            return null;
        }

        return new LoopFinding(
            detector: 'frequency',
            tool: $call->tool,
            fingerprint: $call->exactFingerprint(),
            evidence: ['tool_calls' => $count, 'window_size' => $context->windowSize],
        );
    }
}
