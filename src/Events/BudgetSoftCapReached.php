<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Events;

use Imgul\AgentGuard\Budget\BudgetScope;

/**
 * Fired when a reservation is allowed but crosses the soft cap — the
 * downshift path. Carries scope and spend, never raw usage payloads.
 */
final class BudgetSoftCapReached
{
    public function __construct(
        public readonly BudgetScope $scope,
        public readonly string $key,
        public readonly float $spent,
        public readonly float $cap,
        public readonly float $estimated,
    ) {}
}
