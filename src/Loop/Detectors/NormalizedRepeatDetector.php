<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop\Detectors;

use Imgul\AgentGuard\Loop\DetectorContext;
use Imgul\AgentGuard\Loop\DetectorInterface;
use Imgul\AgentGuard\Loop\LoopFinding;
use Imgul\AgentGuard\Loop\ToolCall;

/**
 * FR-1.2: normalized-argument fingerprinting.
 *
 * Trips when the same normalized fingerprint has been observed maxRepeats
 * times, catching cosmetic argument variation that evades exact matching.
 */
final class NormalizedRepeatDetector implements DetectorInterface
{
    public function detect(ToolCall $call, DetectorContext $context): ?LoopFinding
    {
        if ($context->isExempt($call->tool)) {
            return null;
        }

        $fingerprint = $call->normalizedFingerprint();
        $count = $this->countInWindow($context->window, $fingerprint) + 1; // include the current call

        if ($count < $context->maxRepeats) {
            return null;
        }

        return new LoopFinding(
            detector: 'normalized-repeat',
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
            if ($entry->normalizedFingerprint() === $fingerprint) {
                $count++;
            }
        }

        return $count;
    }
}
