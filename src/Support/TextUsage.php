<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support;

/**
 * Normalized token-usage value object.
 *
 * Maps the exact accounting fields reported by `laravel/ai`'s
 * `AgentResponse->usage` (a `TextUsage` instance) into one framework-agnostic
 * value object. The primary budget path uses exact usage — never
 * char-estimation (PRD FR-2.2).
 *
 * Field semantics mirror `Laravel\Ai\Responses\Data\TextUsage`:
 *  - inputTokens            total input tokens (including cached/cache-written)
 *  - outputTokens           total output tokens (including reasoning)
 *  - cacheReadInputTokens   subset of input read from a prompt cache (nullable)
 *  - cacheWriteInputTokens  subset of input written to a prompt cache (nullable)
 *  - reasoningTokens        subset of output spent on reasoning (nullable)
 */
final class TextUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $cacheReadInputTokens = null,
        public readonly ?int $cacheWriteInputTokens = null,
        public readonly ?int $reasoningTokens = null,
    ) {}

    /**
     * Reconstruct from a serialized payload (e.g. AgentResponse->usage->toArray()).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: (int) ($data['input_tokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? 0),
            cacheReadInputTokens: isset($data['cache_read_input_tokens']) ? (int) $data['cache_read_input_tokens'] : null,
            cacheWriteInputTokens: isset($data['cache_write_input_tokens']) ? (int) $data['cache_write_input_tokens'] : null,
            reasoningTokens: isset($data['reasoning_tokens']) ? (int) $data['reasoning_tokens'] : null,
        );
    }

    /**
     * Sum two usages into a new instance, preserving null for unreported fields.
     */
    public function add(self $usage): self
    {
        return new self(
            inputTokens: $this->inputTokens + $usage->inputTokens,
            outputTokens: $this->outputTokens + $usage->outputTokens,
            cacheReadInputTokens: self::sum($this->cacheReadInputTokens, $usage->cacheReadInputTokens),
            cacheWriteInputTokens: self::sum($this->cacheWriteInputTokens, $usage->cacheWriteInputTokens),
            reasoningTokens: self::sum($this->reasoningTokens, $usage->reasoningTokens),
        );
    }

    /**
     * Total tokens billed (input + output).
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Whether this usage reports no tokens at all.
     */
    public function isEmpty(): bool
    {
        return $this->inputTokens === 0 && $this->outputTokens === 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    private static function sum(?int $a, ?int $b): ?int
    {
        return $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);
    }
}
