<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Console;

use Imgul\AgentGuard\Facades\AgentGuard;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Illuminate\Console\Command;

/**
 * agent-guard:status — operational health of the guard runtime.
 *
 * With a tenant argument, prints that tenant's budgets (spent vs caps),
 * breaker state, and kill-switch state. Without one, prints a global
 * overview: the active mode, every open kill switch, and every tripped
 * breaker. (API §7)
 */
final class AgentGuardStatusCommand extends Command
{
    protected $signature = 'agent-guard:status {tenant? : The tenant id to inspect in detail}';

    protected $description = 'Show agent-guard status: budgets, breaker states, and kill-switch state';

    public function handle(): int
    {
        $runtime = app(GuardRuntime::class);
        $tenant = $this->argument('tenant');

        $this->line('<fg=cyan>laravel-agent-guard status</>');
        $this->line('  mode   : <fg=yellow>'.AgentGuard::mode().'</>');
        $this->line('  driver : <fg=yellow>'.AgentGuard::driver().'</>');
        $this->line('');

        if (is_string($tenant) && $tenant !== '') {
            $this->showTenant($tenant, $runtime);

            return self::SUCCESS;
        }

        $this->showGlobal($runtime);

        return self::SUCCESS;
    }

    private function showTenant(string $tenant, GuardRuntime $runtime): void
    {
        /** @var array{mode: string, driver: string, kill_switch: bool, breaker: string, budgets: array<string, array{cap: float|null, spent: float}>} $status */
        $status = AgentGuard::status($tenant);

        $this->line('<fg=green>Tenant: '.$tenant.'</>');
        $this->line('  kill switch : '.($status['kill_switch'] ? '<fg=red;bg=yellow>OPEN</>' : '<fg=green>closed</>'));
        $this->line('  breaker     : <fg=yellow>'.$status['breaker'].'</>  (keyed per-run)');
        $this->line('');

        $rows = [];

        foreach ($status['budgets'] as $key => $budget) {
            $cap = $budget['cap'] === null ? '∞' : $budget['cap'].' '.$this->unitLabel($key);
            $spent = number_format($budget['spent'], 4);
            $remaining = $budget['cap'] === null
                ? '—'
                : number_format(max(0.0, $budget['cap'] - $budget['spent']), 4);

            $rows[] = [$key, $cap, $spent, $remaining];
        }

        $this->table(['Budget', 'Cap', 'Spent', 'Remaining'], $rows);
    }

    private function showGlobal(GuardRuntime $runtime): void
    {
        $open = $runtime->killSwitch->openScopes();

        if ($open === []) {
            $this->line('  kill switches : <fg=green>none open</>');
        } else {
            $this->line('  kill switches : <fg=red;bg=yellow>OPEN</>');

            foreach ($open as $scope => $keys) {
                foreach ($keys as $key) {
                    $this->line('    - '.$scope.':'.$key);
                }
            }
        }

        $this->line('');

        $snapshot = $runtime->breaker->snapshot();

        if ($snapshot === []) {
            $this->line('  breakers       : <fg=green>none tripped</>');
        } else {
            $this->line('  breakers       : <fg=yellow>tripped</>');

            foreach ($snapshot as $key => $state) {
                $this->line('    - '.$key.': '.$state);
            }
        }
    }

    private function unitLabel(string $key): string
    {
        return str_contains($key, 'tokens') ? 'tok' : 'USD';
    }
}
