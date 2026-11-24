<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Exceptions;

use RuntimeException;

/**
 * Thrown when loop braking halts a guarded run/tool in enforce mode.
 * Machine-readable: carries the detector and the offending tool.
 */
final class LoopDetectedException extends RuntimeException
{
    public function __construct(
        string $message = 'Loop detected; guarded execution halted.',
        public readonly string $detector = 'loop',
        public readonly string $tool = '',
    ) {
        parent::__construct($message);
    }
}
