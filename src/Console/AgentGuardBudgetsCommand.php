<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Console;

use Imgul\AgentGuard\Facades\AgentGuard;
use Illuminate\Console\Command;

/**
 * agent-guard:budgets — per-scope spend summary for a tenant.
 *
 * Prints each configured budget cap (USD and token scopes) with its
 * current spend and remaining allowance, read directly from the ledger.
 * (API §7)
 */
final class AgentGuardBudgetsCommand extends Command
{
    protected $signature = 'agent-guard:budgets {tenant : The tenant id to report on}';

    protected $description = 'Show per-scope budget spend vs caps for a tenant';

    public function handle(): int
    {
        $tenant = (string) $this->argument('tenant');

        $budgets = AgentGuard::budgets($tenant);

        if ($budgets === []) {
            $this->warn('No USD or token budget caps are configured. Add entries to the "budget.defaults" config key.');

            return self::SUCCESS;
        }

        $this->line('<fg=cyan>Budgets for tenant: '.$tenant.'</>');
        $this->line('');

        $rows = [];

        foreach ($budgets as $key => $budget) {
            $cap = $budget['cap'] === null ? '∞' : number_format($budget['cap'], 4);
            $spent = number_format($budget['spent'], 4);
            $remaining = $budget['cap'] === null
                ? '—'
                : number_format(max(0.0, $budget['cap'] - $budget['spent']), 4);
            $utilization = $budget['cap'] === null || $budget['cap'] <= 0
                ? '—'
                : number_format(($budget['spent'] / $budget['cap']) * 100, 1).'%';

            $rows[] = [$key, $cap, $spent, $remaining, $utilization];
        }

        $this->table(['Budget', 'Cap', 'Spent', 'Remaining', 'Used'], $rows);

        return self::SUCCESS;
    }
}
