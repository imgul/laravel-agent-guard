<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Budget\Drivers;

use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetResult;
use Imgul\AgentGuard\Budget\Ledger;
use RuntimeException;

/**
 * Atomic Redis Lua ledger (PRD FR-2.3 — the TOCTOU solution).
 *
 * reserve() runs reserve.lua in a single atomic round-trip: concurrent
 * Horizon workers can never collectively pass a check and each spend. The
 * soft-cap signal is returned in the same call so the downshift decision is
 * free.
 *
 * The phpredis client is injected so the driver is testable against a real
 * Redis (CI) or a Lua-capable in-memory server. The Lua script is loaded
 * from the canonical reserve.lua file (single source of truth).
 */
final class RedisLedger implements Ledger
{
    private const SCRIPT_PATH = __DIR__.'/../Lua/reserve.lua';

    /**
     * @var string|null
     */
    private static ?string $script = null;

    public function __construct(
        private readonly \Redis $redis,
    ) {}

    public function reserve(BudgetReservation $reservation): BudgetResult
    {
        $key = $reservation->key();

        $result = $this->redis->eval(
            $this->script(),
            [
                $key,
                $reservation->cost,
                $reservation->softCap,
                $reservation->hardCap,
                $reservation->ttlSeconds,
            ],
            1,
        );

        $code = is_numeric($result) ? (int) $result : 0;

        return match ($code) {
            0 => BudgetResult::denied($key, $this->spent($key)),
            101 => BudgetResult::allowedSoftExceeded($key, $this->spent($key)),
            default => BudgetResult::allowed($key, $this->spent($key)),
        };
    }

    public function reconcile(BudgetReservation $reservation, float $actualCost): float
    {
        $key = $reservation->key();
        $excess = $reservation->cost - $actualCost;

        if ($excess === 0.0) {
            return 0.0;
        }

        // Release (or top up) the difference atomically. INCRBYFLOAT is a
        // single command; TTL is untouched so the period key keeps rolling.
        $this->redis->incrbyfloat($key, -$excess);

        return $excess;
    }

    public function spent(string $key): float
    {
        $value = $this->redis->get($key);

        if ($value === false || $value === null) {
            return 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function script(): string
    {
        return self::$script ??= self::loadScript();
    }

    private static function loadScript(): string
    {
        if (! is_file(self::SCRIPT_PATH)) {
            throw new RuntimeException('reserve.lua not found at '.self::SCRIPT_PATH);
        }

        return (string) file_get_contents(self::SCRIPT_PATH);
    }
}
