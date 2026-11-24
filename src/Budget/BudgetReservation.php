<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget;

/**
 * A single budget reservation request handed to a ledger driver.
 *
 * The budget key is assembled by the caller from tenant-derived context
 * (scope + unit + tenant/agent/period) — never from agent-supplied values.
 *
 * The unit (usd/tokens) is part of the key segments: the BudgetGuard
 * prepends it to keyParts so USD and token ledgers for the same scope stay
 * separate and never collide.
 */
final class BudgetReservation
{
    /**
     * @param  list<string>  $keyParts  segments after the scope, e.g. [unit, tenantId, period]
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
     * The full Redis/DB key, e.g. agent-guard:budget:tenant:usd:{tenantId}:{period}.
     */
    public function key(): string
    {
        return 'agent-guard:budget:'.$this->scope->value.':'.implode(':', $this->keyParts);
    }
}
