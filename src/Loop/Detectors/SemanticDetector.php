<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop\Detectors;

use Imgul\AgentGuard\Loop\DetectorContext;
use Imgul\AgentGuard\Loop\DetectorInterface;
use Imgul\AgentGuard\Loop\LoopFinding;
use Imgul\AgentGuard\Loop\ToolCall;

/**
 * FR-1.5: semantic near-match detection.
 *
 * Compares the current call against window entries using the configured
 * similarity strategy. To bound latency/cost, the LoopDetector only runs
 * this detector when the frequency detector has already signalled elevated
 * call-rate, and the strategy may itself be a local fast path (SimHash,
 * MinHash, trigram Jaccard) or an embedding adapter supplied by the user.
 */
final class SemanticDetector implements DetectorInterface
{
    public function detect(ToolCall $call, DetectorContext $context): ?LoopFinding
    {
        if ($context->isExempt($call->tool) || $context->similarity === null) {
            return null;
        }

        $current = $call->normalizedText();

        foreach ($context->window as $entry) {
            if ($entry->tool !== $call->tool) {
                continue;
            }

            $score = $context->similarity->similarity($current, $entry->normalizedText());

            if ($score >= $context->similarityThreshold) {
                return new LoopFinding(
                    detector: 'semantic',
                    tool: $call->tool,
                    fingerprint: $call->normalizedFingerprint(),
                    evidence: [
                        'similarity' => $score,
                        'threshold' => $context->similarityThreshold,
                    ],
                );
            }
        }

        return null;
    }
}
