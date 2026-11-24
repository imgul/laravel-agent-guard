<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support\Similarity;

/**
 * Local similarity strategy used by the semantic loop detector.
 *
 * Implementations must be cheap and dependency-free. Embedding-based
 * comparison is exposed through EmbeddingSimilarity, which wraps a user-supplied
 * EmbeddingAdapterInterface; the engine only escalates to it when local
 * frequency is elevated (PRD FR-1.5).
 */
interface SimilarityStrategy
{
    /**
     * Similarity between two normalized fingerprints, in the range 0.0 - 1.0.
     */
    public function similarity(string $a, string $b): float;
}
