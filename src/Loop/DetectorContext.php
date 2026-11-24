<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;

/**
 * Read-only view of the loop detector's current state, handed to each
 * detector strategy so they stay decoupled from the orchestrator.
 */
final class DetectorContext
{
    /**
     * @param  list<ToolCall>  $window
     * @param  array<string, true>  $exemptTools
     */
    public function __construct(
        public readonly array $window,
        public readonly int $windowSize,
        public readonly int $maxRepeats,
        public readonly float $maxStepGrowthRatio,
        public readonly int $frequencyThreshold,
        public readonly ?SimilarityStrategy $similarity,
        public readonly float $similarityThreshold,
        public readonly array $exemptTools,
        public readonly int $lastInputTokens,
        public readonly array $lastOutputFingerprints,
        public readonly int $currentInputTokens,
        public readonly array $currentOutputFingerprints,
    ) {}

    /**
     * Whether the previous step produced no new tool outputs (same output
     * fingerprint set), i.e. the step made no progress.
     */
    public function hasNoProgress(): bool
    {
        return $this->currentOutputFingerprints === $this->lastOutputFingerprints;
    }

    /**
     * Step-over-step input-token growth ratio, or 0 when the previous step
     * reported no token count.
     */
    public function stepGrowthRatio(): float
    {
        if ($this->lastInputTokens <= 0) {
            return 0.0;
        }

        return $this->currentInputTokens / $this->lastInputTokens;
    }

    /**
     * Whether the tool is exempt from loop detection (pagination/polling/
     * batch/idempotency allowlists).
     */
    public function isExempt(string $tool): bool
    {
        return isset($this->exemptTools[$tool]);
    }
}
