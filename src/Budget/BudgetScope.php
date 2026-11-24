<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget;

/**
 * The four budget scopes (PRD FR-2.1), each enforceable in tokens and USD.
 */
enum BudgetScope: string
{
    case Run = 'run';
    case Agent = 'agent';
    case Tenant = 'tenant';
    case Tool = 'tool';

    /**
     * The daily period key segment for scopes that roll over per day.
     */
    public function isDaily(): bool
    {
        return $this === self::Agent || $this === self::Tenant || $this === self::Tool;
    }
}
