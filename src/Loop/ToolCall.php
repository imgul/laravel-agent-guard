<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Loop;

use Imgul\AgentGuard\Support\Fingerprint;

/**
 * A single tool call observed during a guarded step.
 */
final class ToolCall
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $args,
    ) {}

    /**
     * Exact fingerprint: sha256(tool . ':' . canonical args).
     */
    public function exactFingerprint(): string
    {
        return Fingerprint::exact($this->tool, $this->args);
    }

    /**
     * Normalized fingerprint: cosmetic variation stripped.
     */
    public function normalizedFingerprint(): string
    {
        return Fingerprint::normalized($this->tool, $this->args);
    }

    /**
     * Canonical string form used by similarity strategies.
     */
    public function normalizedText(): string
    {
        return $this->tool.':'.json_encode(
            Fingerprint::normalizedArgs($this->args),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
