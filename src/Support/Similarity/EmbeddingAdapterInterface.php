<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support\Similarity;

/**
 * Pluggable embedding adapter. The package ships no embedding provider
 * dependency; users supply one (e.g. a Laravel AI embedding call) and the
 * engine escalates to embeddings only when local frequency is elevated.
 */
interface EmbeddingAdapterInterface
{
    /**
     * Embed a normalized fingerprint into a dense vector.
     *
     * @return list<float>
     */
    public function embed(string $fingerprint): array;
}
