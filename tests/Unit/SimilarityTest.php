<?php

declare(strict_types=1);

use Imgul\AgentGuard\Support\Similarity\EmbeddingAdapterInterface;
use Imgul\AgentGuard\Support\Similarity\EmbeddingSimilarity;
use Imgul\AgentGuard\Support\Similarity\MinHash;
use Imgul\AgentGuard\Support\Similarity\SimHash;
use Imgul\AgentGuard\Support\Similarity\TrigramJaccard;

it('returns 1.0 for identical inputs across all local strategies', function (): void {
    $input = 'search:{"q":"hello world","page":1}';

    foreach ([new SimHash, new MinHash, new TrigramJaccard] as $strategy) {
        expect($strategy->similarity($input, $input))->toBe(1.0);
    }
});

it('scores similar inputs higher than dissimilar ones', function (): void {
    $similarA = 'search:{"q":"find user by email"}';
    $similarB = 'search:{"q":"look up user by email address"}';
    $different = 'search:{"q":"delete all records from database"}';

    $simhash = new SimHash;
    $trigram = new TrigramJaccard;

    expect($simhash->similarity($similarA, $similarB))
        ->toBeGreaterThan($simhash->similarity($similarA, $different));

    expect($trigram->similarity($similarA, $similarB))
        ->toBeGreaterThan($trigram->similarity($similarA, $different));
});

it('keeps similarity within the 0..1 range', function (): void {
    foreach ([new SimHash, new MinHash, new TrigramJaccard] as $strategy) {
        $score = $strategy->similarity('a:{"x":1}', 'b:{"y":2}');

        expect($score)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    }
});

it('computes cosine similarity via the embedding adapter', function (): void {
    $strategy = new EmbeddingSimilarity(new class implements EmbeddingAdapterInterface
    {
        public function embed(string $fingerprint): array
        {
            return $fingerprint === 'a' ? [1.0, 0.0] : [0.0, 1.0];
        }
    });

    expect($strategy->similarity('a', 'a'))->toBe(1.0);
    expect($strategy->similarity('a', 'b'))->toBe(0.0);
});
