<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Console;

use Imgul\AgentGuard\Facades\AgentGuard;
use Imgul\AgentGuard\Loop\KillSwitch;
use Illuminate\Console\Command;

/**
 * agent-guard:kill — open the kill switch to halt guarded execution.
 *
 * Three targeting modes (API §7):
 *  - `--tenant=acme`  halt every guarded run for one tenant.
 *  - `--run=abc123`   halt a single agent run by its run id.
 *  - `--global`       halt every guarded run, everywhere.
 *
 * If none of the targeting options are given, the kill switch opens for
 * the global scope (same as --global).
 *
 * This is the incident-response control: the next guarded step for the
 * scoped key hard-halts before any work or spend.
 */
final class AgentGuardKillCommand extends Command
{
    protected $signature = 'agent-guard:kill
        {--tenant= : The tenant id to halt (opens the tenant kill switch)}
        {--run=    : A specific run id to halt (opens the run-scope kill switch)}
        {--global : Open the global kill switch, halting every run everywhere}';

    protected $description = 'Open the agent-guard kill switch (incident response)';

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        $run = $this->option('run');

        // Validate at most one targeting option.
        $options = [
            'tenant' => $tenant,
            'run' => $run,
            'global' => $this->option('global'),
        ];

        $active = array_filter(
            $options,
            fn ($value) => $value !== null && $value !== false && $value !== '',
        );

        if (count($active) > 1) {
            $this->error('Specify only one targeting option: --tenant, --run, or --global.');

            return self::FAILURE;
        }

        if (is_string($run) && $run !== '') {
            $opened = AgentGuard::killRun($run);

            $this->info(
                $opened
                    ? "Kill switch opened for run [{$run}]. This run will halt on its next step."
                    : "Kill switch for run [{$run}] was already open."
            );

            return self::SUCCESS;
        }

        if (is_string($tenant) && $tenant !== '') {
            $opened = AgentGuard::kill($tenant);

            $this->info(
                $opened
                    ? "Kill switch opened for tenant [{$tenant}]. All guarded runs for this tenant will halt."
                    : "Kill switch for tenant [{$tenant}] was already open."
            );

            return self::SUCCESS;
        }

        // Default: global kill.
        $opened = AgentGuard::killGlobal();

        $this->info(
            $opened
                ? sprintf('Global kill switch opened. All guarded runs (scope %s) will halt.', KillSwitch::GLOBAL_KEY)
                : 'Global kill switch was already open.'
        );

        return self::SUCCESS;
    }
}
