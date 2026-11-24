<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop\Detectors;

use Imgul\AgentGuard\Loop\DetectorContext;
use Imgul\AgentGuard\Loop\DetectorInterface;
use Imgul\AgentGuard\Loop\LoopFinding;
use Imgul\AgentGuard\Loop\ToolCall;

/**
 * FR-1.1: exact-repeat fingerprinting over the sliding window.
 * Trips when the same exact fingerprint has been observed maxRepeats times.
 */
final class ExactRepeatDetector implements DetectorInterface
{
    public function detect(ToolCall $call, DetectorContext $context): ?LoopFinding
    {
        if ($context->isExempt($call->tool)) {
            return null;
        }

        $fingerprint = $call->exactFingerprint();
        $count = $this->countInWindow($context->window, $fingerprint) + 1; // include the current call

        if ($count < $context->maxRepeats) {
            return null;
        }

        return new LoopFinding(
            detector: 'exact-repeat',
            tool: $call->tool,
            fingerprint: $fingerprint,
            evidence: ['repeats' => $count, 'window_size' => $context->windowSize],
        );
    }

    /**
     * @param  list<ToolCall>  $window
     */
    private function countInWindow(array $window, string $fingerprint): int
    {
        $count = 0;

        foreach ($window as $entry) {
            if ($entry->exactFingerprint() === $fingerprint) {
                $count++;
            }
        }

        return $count;
    }
}
