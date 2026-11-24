<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop\Detectors;

use Imgul\AgentGuard\Loop\DetectorContext;
use Imgul\AgentGuard\Loop\DetectorInterface;
use Imgul\AgentGuard\Loop\LoopFinding;
use Imgul\AgentGuard\Loop\ToolCall;

/**
 * FR-1.4: context-growth detection.
 *
 * Trips when the step-over-step input-token growth ratio exceeds
 * maxStepGrowthRatio while the step produced no new tool outputs (no
 * progress) — the signature of a reasoning loop / compounding context
 * bloat (the 5x-cost, 87%-single-step failure mode).
 */
final class ContextGrowthDetector implements DetectorInterface
{
    public function detect(ToolCall $call, DetectorContext $context): ?LoopFinding
    {
        if ($context->isExempt($call->tool)) {
            return null;
        }

        if ($context->lastInputTokens <= 0) {
            return null;
        }

        if (! $context->hasNoProgress()) {
            return null;
        }

        $ratio = $context->stepGrowthRatio();

        if ($ratio <= $context->maxStepGrowthRatio) {
            return null;
        }

        return new LoopFinding(
            detector: 'context-growth',
            tool: $call->tool,
            fingerprint: $call->exactFingerprint(),
            evidence: [
                'growth_ratio' => $ratio,
                'last_input_tokens' => $context->lastInputTokens,
                'current_input_tokens' => $context->currentInputTokens,
                'max_step_growth_ratio' => $context->maxStepGrowthRatio,
            ],
        );
    }
}
