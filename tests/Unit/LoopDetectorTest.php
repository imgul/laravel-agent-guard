<?php

declare(strict_types=1);

use Imgul\AgentGuard\Loop\LoopDetector;
use Imgul\AgentGuard\Loop\ToolCall;
use Imgul\AgentGuard\Support\Similarity\SimHash;
use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeDetector(array $overrides = []): LoopDetector
{
    /** @var int $windowSize */
    $windowSize = is_numeric($overrides['window_size'] ?? null) ? (int) $overrides['window_size'] : 50;

    /** @var int $maxRepeats */
    $maxRepeats = is_numeric($overrides['max_repeats'] ?? null) ? (int) $overrides['max_repeats'] : 3;

    /** @var float $maxStepGrowthRatio */
    $maxStepGrowthRatio = is_numeric($overrides['max_step_growth_ratio'] ?? null) ? (float) $overrides['max_step_growth_ratio'] : 2.5;

    /** @var int $frequencyThreshold */
    $frequencyThreshold = is_numeric($overrides['frequency_threshold'] ?? null) ? (int) $overrides['frequency_threshold'] : 3;

    /** @var SimilarityStrategy|null $similarity */
    $similarity = $overrides['similarity'] ?? null;

    /** @var float $similarityThreshold */
    $similarityThreshold = is_numeric($overrides['similarity_threshold'] ?? null) ? (float) $overrides['similarity_threshold'] : 0.85;

    /** @var list<string> $exemptTools */
    $exemptTools = $overrides['exempt_tools'] ?? [];

    return new LoopDetector(
        windowSize: $windowSize,
        maxRepeats: $maxRepeats,
        maxStepGrowthRatio: $maxStepGrowthRatio,
        frequencyThreshold: $frequencyThreshold,
        similarity: $similarity,
        similarityThreshold: $similarityThreshold,
        exemptTools: array_fill_keys($exemptTools, true),
    );
}

it('does not flag a tool below the repeat threshold', function (): void {
    $detector = makeDetector();

    $findings = $detector->check(new ToolCall('search', ['q' => 'a']), 100);
    $findings2 = $detector->check(new ToolCall('search', ['q' => 'b']), 100);

    expect($findings)->toBeEmpty();
    expect($findings2)->toBeEmpty();
});

it('trips exact-repeat at the configured threshold', function (): void {
    $detector = makeDetector(['max_repeats' => 3]);

    $detector->check(new ToolCall('search', ['q' => 'loop']), 100);
    $detector->check(new ToolCall('search', ['q' => 'loop']), 100);
    $findings = $detector->check(new ToolCall('search', ['q' => 'loop']), 100);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->detector)->toBe('exact-repeat');
    expect($findings[0]->tool)->toBe('search');
    expect($findings[0]->evidence['repeats'])->toBe(3);
});

it('trips normalized-repeat on cosmetic argument variation', function (): void {
    $detector = makeDetector(['max_repeats' => 3]);

    $detector->check(new ToolCall('search', ['q' => 'hello', 'page' => 1]), 100);
    $detector->check(new ToolCall('search', ['page' => 2, 'q' => 'hello  ']), 100);
    $findings = $detector->check(new ToolCall('search', ['q' => 'hello', 'page' => 99]), 100);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->detector)->toBe('normalized-repeat');
});

it('trips frequency on raw call-rate spike regardless of arguments', function (): void {
    $detector = makeDetector(['max_repeats' => 99, 'frequency_threshold' => 3]);

    $detector->check(new ToolCall('fetch', ['id' => 1]), 100);
    $detector->check(new ToolCall('fetch', ['id' => 2]), 100);
    $findings = $detector->check(new ToolCall('fetch', ['id' => 3]), 100);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->detector)->toBe('frequency');
    expect($findings[0]->evidence['tool_calls'])->toBe(3);
});

it('trips context-growth on token growth with no progress', function (): void {
    $detector = makeDetector(['max_step_growth_ratio' => 2.5]);

    $detector->check(new ToolCall('search', ['q' => 'a']), 1000, ['out-1']);
    // Input grows 3x, outputs identical -> reasoning loop.
    $findings = $detector->check(new ToolCall('search', ['q' => 'a']), 3000, ['out-1']);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->detector)->toBe('context-growth');
    expect($findings[0]->evidence['growth_ratio'])->toBe(3.0);
});

it('does not trip context-growth when the step produced new output', function (): void {
    $detector = makeDetector(['max_step_growth_ratio' => 2.5]);

    $detector->check(new ToolCall('search', ['q' => 'a']), 1000, ['out-1']);
    $findings = $detector->check(new ToolCall('search', ['q' => 'a']), 3000, ['out-1', 'out-2']);

    $detectors = array_column($findings, 'detector');

    expect($detectors)->not->toContain('context-growth');
});

it('does not trip context-growth below the growth ratio', function (): void {
    $detector = makeDetector(['max_step_growth_ratio' => 2.5]);

    $detector->check(new ToolCall('search', ['q' => 'a']), 1000, ['out-1']);
    $findings = $detector->check(new ToolCall('search', ['q' => 'a']), 2000, ['out-1']);

    expect(array_column($findings, 'detector'))->not->toContain('context-growth');
});

it('only escalates to semantic similarity when frequency is elevated', function (): void {
    $detector = makeDetector([
        'max_repeats' => 99,
        'frequency_threshold' => 3,
        'similarity' => new SimHash,
    ]);

    // Identical normalized text gives SimHash similarity 1.0 (>= the 0.85
    // threshold). maxRepeats=99 keeps the repeat detectors from firing, so
    // the 3rd call trips frequency first, then the gated semantic detector.
    $detector->check(new ToolCall('search', ['q' => 'find user by email']), 100);
    $detector->check(new ToolCall('search', ['q' => 'find user by email']), 100);

    // Frequency is now elevated (3rd call); semantic near-match runs.
    $findings = $detector->check(
        new ToolCall('search', ['q' => 'find user by email']),
        100,
    );

    expect(array_column($findings, 'detector'))->toContain('semantic');
});

it('does not run semantic detection when frequency is not elevated', function (): void {
    $detector = makeDetector([
        'max_repeats' => 99,
        'frequency_threshold' => 3,
        'similarity' => new SimHash,
    ]);

    $findings = $detector->check(new ToolCall('search', ['q' => 'find user by email']), 100);

    expect(array_column($findings, 'detector'))->not->toContain('semantic');
});

it('exempts configured tools from detection', function (): void {
    $detector = makeDetector(['max_repeats' => 2, 'exempt_tools' => ['poll']]);

    $findings = $detector->check(new ToolCall('poll', ['id' => 1]), 100);
    $findings2 = $detector->check(new ToolCall('poll', ['id' => 1]), 100);

    expect($findings)->toBeEmpty();
    expect($findings2)->toBeEmpty();
    expect($detector->hasExemptions())->toBeTrue();
});

it('bounds the sliding window', function (): void {
    $detector = makeDetector(['window_size' => 2, 'max_repeats' => 2]);

    $detector->check(new ToolCall('search', ['q' => 'a']), 100);
    $detector->check(new ToolCall('search', ['q' => 'b']), 100);
    $detector->check(new ToolCall('search', ['q' => 'c']), 100);

    expect($detector->windowSize())->toBe(2);

    // The first 'a' call has fallen out of the window (window is [b, c]).
    // Repeating 'c' once more puts two 'c' calls inside the window, which
    // trips at the threshold.
    $detector->check(new ToolCall('search', ['q' => 'c']), 100);
    $findings = $detector->check(new ToolCall('search', ['q' => 'c']), 100);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->detector)->toBe('exact-repeat');
});

it('resets detector state', function (): void {
    $detector = makeDetector(['max_repeats' => 2]);

    $detector->check(new ToolCall('search', ['q' => 'a']), 100);
    $detector->check(new ToolCall('search', ['q' => 'a']), 100);
    expect($detector->check(new ToolCall('search', ['q' => 'a']), 100))->not->toBeEmpty();

    $detector->reset();

    expect($detector->windowSize())->toBe(0);
    expect($detector->check(new ToolCall('search', ['q' => 'a']), 100))->toBeEmpty();
});
