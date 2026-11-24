<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support\Similarity;

/**
 * Cosine-similarity strategy backed by a user-supplied embedding adapter.
 *
 * Only consulted by the semantic detector when local frequency is elevated,
 * so embedding cost is bounded by the frequency detector's gating.
 */
final class EmbeddingSimilarity implements SimilarityStrategy
{
    public function __construct(
        private readonly EmbeddingAdapterInterface $adapter,
    ) {}

    public function similarity(string $a, string $b): float
    {
        $vectorA = $this->adapter->embed($a);
        $vectorB = $this->adapter->embed($b);

        if ($vectorA === [] || $vectorB === []) {
            return 0.0;
        }

        $length = min(count($vectorA), count($vectorB));

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $vectorA[$i] * $vectorB[$i];
            $normA += $vectorA[$i] ** 2;
            $normB += $vectorB[$i] ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
