<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support\Similarity;

/**
 * Trigram-Jaccard similarity over the raw normalized fingerprint string.
 * Cheap and robust for short tool-argument payloads.
 */
final class TrigramJaccard implements SimilarityStrategy
{
    public function similarity(string $a, string $b): float
    {
        $gramsA = $this->trigrams($a);
        $gramsB = $this->trigrams($b);

        if ($gramsA === [] || $gramsB === []) {
            return $a === $b ? 1.0 : 0.0;
        }

        $intersection = count(array_intersect($gramsA, $gramsB));

        $union = $intersection + (count($gramsA) - $intersection) + (count($gramsB) - $intersection);

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * @return array<string, true>
     */
    private function trigrams(string $text): array
    {
        $text = ' '.preg_replace('/\s+/u', ' ', trim($text)).' ';

        $grams = [];

        $length = strlen($text);

        for ($i = 0; $i + 3 <= $length; $i++) {
            $grams[substr($text, $i, 3)] = true;
        }

        return $grams;
    }
}
