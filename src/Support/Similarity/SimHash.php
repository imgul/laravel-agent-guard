<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support\Similarity;

/**
 * Local 64-bit SimHash over word tokens. Zero API cost, sub-millisecond.
 */
final class SimHash implements SimilarityStrategy
{
    private const BITS = 64;

    public function similarity(string $a, string $b): float
    {
        $tokensA = $this->tokens($a);
        $tokensB = $this->tokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return $a === $b ? 1.0 : 0.0;
        }

        $signatureA = $this->signature($tokensA);
        $signatureB = $this->signature($tokensB);

        if ($signatureA === $signatureB) {
            return 1.0;
        }

        $distance = 0;

        for ($i = 0; $i < self::BITS; $i++) {
            if ((($signatureA >> $i) & 1) !== (($signatureB >> $i) & 1)) {
                $distance++;
            }
        }

        return 1.0 - ($distance / self::BITS);
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
     */
    private function signature(array $tokens): int
    {
        $vector = array_fill(0, self::BITS, 0);

        foreach ($tokens as $token) {
            $hash = (int) hexdec(substr(hash('sha256', $token), 0, 16));

            for ($i = 0; $i < self::BITS; $i++) {
                $vector[$i] += ((int) (($hash >> $i) & 1)) === 1 ? 1 : -1;
            }
        }

        $signature = 0;

        for ($i = 0; $i < self::BITS; $i++) {
            if ($vector[$i] > 0) {
                $signature |= 1 << $i;
            }
        }

        return $signature;
    }
}
