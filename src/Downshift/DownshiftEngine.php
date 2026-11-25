<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Downshift;

/**
 * DownshiftEngine: the capability-cliff mitigation ladder (PRD FR-3.2).
 *
 * Framework-agnostic. Given an ordered ladder of tiers and the current
 * spend fraction, it selects the deepest applicable tier — the one with the
 * highest threshold that the spend has crossed. The middleware (adapter)
 * then applies that tier's model/tool/token policy to the PendingStep.
 *
 * A ladder is typically frontier -> mid -> mini: as spend rises past each
 * threshold, the agent is moved to a cheaper model with tighter limits, so
 * a degraded-model output that triggers retries cannot spiral into more
 * spend (the failure mode ARCHITECTURE §6 calls the capability cliff).
 */
final class DownshiftEngine
{
    /**
     * @var list<DownshiftTier> sorted by threshold ascending
     */
    private readonly array $tiers;

    /**
     * @param  list<DownshiftTier>  $tiers  sorted by threshold ascending
     */
    public function __construct(array $tiers)
    {
        // Sort by threshold ascending so the deepest applicable tier is the
        // last one the spend fraction crosses. Readonly properties cannot be
        // mutated after initialisation, so sort a local copy then assign once.
        usort($tiers, fn (DownshiftTier $a, DownshiftTier $b): int => $a->threshold <=> $b->threshold);

        $this->tiers = $tiers;
    }

    /**
     * The deepest tier that applies at the given spend fraction, or null when
     * spend is still below the first threshold.
     */
    public function applicable(float $spendFraction): ?DownshiftTier
    {
        $applicable = null;

        foreach ($this->tiers as $tier) {
            if ($tier->appliesTo($spendFraction)) {
                $applicable = $tier;
            } else {
                break;
            }
        }

        return $applicable;
    }

    /**
     * The tier that would fire for the current model, skipping tiers that
     * would not change the model (already at the target).
     */
    public function applicableFor(float $spendFraction, string $currentModel): ?DownshiftTier
    {
        $tier = $this->applicable($spendFraction);

        if ($tier === null || ! $tier->changesModel($currentModel)) {
            return null;
        }

        return $tier;
    }

    /**
     * @return list<DownshiftTier>
     */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /**
     * Whether any tier is configured.
     */
    public function hasTiers(): bool
    {
        return $this->tiers !== [];
    }
}
