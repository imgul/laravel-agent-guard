<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Events;

use Imgul\AgentGuard\Budget\BudgetScope;

/**
 * Fired when a reservation is denied (hard cap breach) or a reconciliation
 * pushes spend past the hard cap.
 */
final class BudgetHardCapReached
{
    public function __construct(
        public readonly BudgetScope $scope,
        public readonly string $key,
        public readonly float $spent,
        public readonly float $cap,
    ) {}
}
