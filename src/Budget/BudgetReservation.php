<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget;

/**
 * A single budget reservation request handed to a ledger driver.
 *
 * The budget key is assembled by the caller from tenant-derived context
 * (scope + tenant/agent/period) — never from agent-supplied values.
 */
final class BudgetReservation
{
    /**
     * @param  list<string>  $keyParts  segments after the scope, e.g. [tenantId, agentId, period]
     */
    public function __construct(
        public readonly BudgetScope $scope,
        public readonly array $keyParts,
        public readonly float $cost,
        public readonly float $softCap,
        public readonly float $hardCap,
        public readonly int $ttlSeconds,
    ) {}

    /**
     * The full Redis/DB key, e.g. agent-guard:budget:tenant:{tenantId}:{period}.
     */
    public function key(): string
    {
        return 'agent-guard:budget:'.$this->scope->value.':'.implode(':', $this->keyParts);
    }
}
