<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Events;

/**
 * Fired when any loop detector triggers.
 *
 * Carries the detector name, the tool, and the fingerprint — never the raw
 * arguments, so no prompt/tool payloads can leak into logs or dashboards.
 */
final class LoopDetected
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
