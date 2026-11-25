<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

use Illuminate\Contracts\Events\Dispatcher;
use Imgul\AgentGuard\Events\LoopDetected;
use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;

/**
 * LoopGuard core: sliding-window orchestration of the loop detectors.
 *
 * One instance serves one guarded scope (a run id, or a scope:tool pair for
 * MCP tools). The detector itself is framework-agnostic; event dispatch is
 * injected so unit tests run without a framework while feature tests assert
 * real event emission.
 *
 * Detector pipeline per step:
 *   exact-repeat -> normalized-repeat -> frequency -> context-growth,
 *   plus semantic near-match gated on elevated frequency (FR-1.5 cost
 *   control: embeddings only run when the raw call-rate is already spiked).
 */
final class LoopDetector
{
    /**
     * @var list<ToolCall>
     */
    private array $window = [];

    /**
     * @var list<string>
     */
    private array $lastOutputFingerprints = [];

    private int $lastInputTokens = 0;

    /**
     * @var list<DetectorInterface>
     */
    private readonly array $detectors;

    /**
     * @param  array<string, true>  $exemptTools
     */
    public function __construct(
        private readonly int $windowSize = 50,
        private readonly int $maxRepeats = 3,
        private readonly float $maxStepGrowthRatio = 2.5,
        private readonly int $frequencyThreshold = 3,
        private readonly ?SimilarityStrategy $similarity = null,
        private readonly float $similarityThreshold = 0.85,
        private readonly array $exemptTools = [],
        private readonly ?Dispatcher $dispatcher = null,
    ) {
        $this->detectors = [
            new Detectors\ExactRepeatDetector,
            new Detectors\NormalizedRepeatDetector,
            new Detectors\FrequencyDetector,
            new Detectors\ContextGrowthDetector,
        ];
    }

    /**
     * Record a tool call and run the detector pipeline.
     *
     * @param  list<string>  $outputFingerprints  Fingerprints of tool outputs
     *                                            produced by the completed step.
     * @return list<LoopFinding> Every finding raised by this step.
     */
    public function check(ToolCall $call, int $inputTokens, array $outputFingerprints = []): array
    {
        $context = new DetectorContext(
            window: $this->window,
            windowSize: $this->windowSize,
            maxRepeats: $this->maxRepeats,
            maxStepGrowthRatio: $this->maxStepGrowthRatio,
            frequencyThreshold: $this->frequencyThreshold,
            similarity: $this->similarity,
            similarityThreshold: $this->similarityThreshold,
            exemptTools: $this->exemptTools,
            lastInputTokens: $this->lastInputTokens,
            lastOutputFingerprints: $this->lastOutputFingerprints,
            currentInputTokens: $inputTokens,
            currentOutputFingerprints: $outputFingerprints,
        );

        $findings = [];
        $frequencyElevated = false;

        foreach ($this->detectors as $detector) {
            $finding = $detector->detect($call, $context);

            if ($finding === null) {
                continue;
            }

            $findings[] = $finding;

            // Report the highest-priority matching detector; the remaining
            // repeat/frequency detectors add no new information once one has
            // fired (a call that exactly repeats also normalized-repeats).
            if ($finding->detector === 'frequency') {
                $frequencyElevated = true;

                break;
            }

            break;
        }

        // FR-1.5: semantic near-match escalates to embeddings (or local
        // similarity strategies) only when the frequency detector has already
        // signalled an elevated call-rate, bounding per-step latency/cost.
        if ($frequencyElevated && $this->similarity !== null) {
            $finding = (new Detectors\SemanticDetector)->detect($call, $context);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $this->window[] = $call;

        if (count($this->window) > $this->windowSize) {
            array_shift($this->window);
        }

        $this->lastInputTokens = $inputTokens;
        $this->lastOutputFingerprints = $outputFingerprints;

        foreach ($findings as $finding) {
            $this->dispatcher?->dispatch(new LoopDetected(
                detector: $finding->detector,
                tool: $finding->tool,
                fingerprint: $finding->fingerprint,
                evidence: $finding->evidence,
            ));
        }

        return $findings;
    }

    /**
     * Reset detector state for a new run or after a successful HALF_OPEN probe.
     */
    public function reset(): void
    {
        $this->window = [];
        $this->lastInputTokens = 0;
        $this->lastOutputFingerprints = [];
    }

    /**
     * Current window size (number of recorded calls).
     */
    public function windowSize(): int
    {
        return count($this->window);
    }

    /**
     * Whether any tool in the window is exempt from detection.
     */
    public function hasExemptions(): bool
    {
        return $this->exemptTools !== [];
    }
}
