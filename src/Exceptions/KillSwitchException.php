<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Exceptions;

use RuntimeException;

/**
 * Thrown when a guarded step is attempted while the kill switch is open.
 * Machine-readable: carries the scope and key of the open switch.
 */
final class KillSwitchException extends RuntimeException
{
    public function __construct(
        string $message = 'Kill switch is open; guarded execution halted.',
        public readonly string $scope = 'run',
        public readonly string $key = '',
    ) {
        parent::__construct($message);
    }
}
