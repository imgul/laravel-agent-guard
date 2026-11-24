<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Exceptions;

use RuntimeException;

/**
 * Thrown when a hard budget cap is breached in enforce mode.
 * Machine-readable: carries the scope, key, spent, and cap.
 */
final class BudgetExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Budget hard cap exceeded; guarded execution halted.',
        public readonly string $scope = 'budget',
        public readonly string $key = '',
        public readonly float $spent = 0.0,
        public readonly float $cap = 0.0,
    ) {
        parent::__construct($message);
    }
}
