<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support\Similarity;

/**
 * Local MinHash over word tokens. Estimates Jaccard similarity with a small
 * signature; zero API cost.
 */
final class MinHash implements SimilarityStrategy
{
    private const HASHES = 16;

    public function similarity(string $a, string $b): float
    {
        $tokensA = $this->tokens($a);
        $tokensB = $this->tokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return $a === $b ? 1.0 : 0.0;
        }

        $signatureA = $this->signature($tokensA);
        $signatureB = $this->signature($tokensB);

        $matches = 0;

        for ($i = 0; $i < self::HASHES; $i++) {
            if ($signatureA[$i] === $signatureB[$i]) {
                $matches++;
            }
        }

        return $matches / self::HASHES;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        return array_values(array_filter(
            preg_split('/\W+/u', mb_strtolower($text)) ?: [],
            fn (string $token): bool => $token !== '',
        ));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    private function signature(array $tokens): array
    {
        $signature = [];

        for ($i = 0; $i < self::HASHES; $i++) {
            $minimum = PHP_INT_MAX;

            foreach ($tokens as $token) {
                $hash = (int) hexdec(substr(hash('sha256', $i.':'.$token), 0, 16));

                if ($hash < $minimum) {
                    $minimum = $hash;
                }
            }

            $signature[] = $minimum;
        }

        return $signature;
    }
}
