<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support;

use Imgul\AgentGuard\Support\Pricing\PricingCatalog;

/**
 * Pessimistic step-cost estimator for the reservation phase (PRD FR-2.2).
 *
 * Before a step runs, the guard reserves an estimated cost against the
 * ledger. The estimate is deliberately conservative (an upper bound on the
 * likely token spend) so concurrent workers cannot collectively pass a cap
 * check and each overspend. After the step, reconciliation adjusts the
 * ledger to the exact `AgentResponse->usage`.
 *
 * Framework-agnostic: takes a model and an estimated input-token count and
 * returns USD + token estimates via the versioned pricing catalog. The
 * middleware (adapter) is responsible for deriving the input-token estimate
 * from the PendingStep's messages.
 */
final class CostEstimator
{
    public function __construct(
        private readonly PricingCatalog $pricing,
        private readonly int $defaultOutputTokens = 256,
    ) {}

    /**
     * The default output-token allowance used for pre-step estimates.
     */
    public function defaultOutputTokens(): int
    {
        return $this->defaultOutputTokens;
    }

    /**
     * Estimated USD cost for a step on the given model.
     *
     * Returns 0.0 when the model has no known price (token budgets still
     * enforce; USD is reported as null/0 by the catalog).
     */
    public function estimateUsd(string $model, int $inputTokens): float
    {
        $usd = $this->pricing->usdFor(
            new TextUsage(inputTokens: $inputTokens, outputTokens: $this->defaultOutputTokens),
            $model,
        );

        return $usd ?? 0.0;
    }

    /**
     * Estimated total tokens (input + default output) for a step.
     */
    public function estimateTokens(int $inputTokens): int
    {
        return $inputTokens + $this->defaultOutputTokens;
    }

    /**
     * Estimated input-token count from a character count (≈ 4 chars/token).
     */
    public function estimateInputTokensFromChars(int $chars): int
    {
        return max(1, (int) floor($chars / 4));
    }

    /**
     * Convert an actual usage (post-step) into USD for reconciliation.
     */
    public function actualUsd(string $model, TextUsage $usage): float
    {
        return $this->pricing->usdFor($usage, $model) ?? 0.0;
    }
}
