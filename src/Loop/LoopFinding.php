<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

/**
 * A single loop detection result produced by a detector.
 */
final class LoopFinding
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $detector,
        public readonly string $tool,
        public readonly string $fingerprint,
        public readonly array $evidence = [],
    ) {}
}
