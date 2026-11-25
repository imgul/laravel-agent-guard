<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Console;

use Imgul\AgentGuard\Facades\AgentGuard;
use Illuminate\Console\Command;

/**
 * agent-guard:kill — open the kill switch to halt guarded execution.
 *
 * Pass --tenant= to halt a single tenant. Without it, the run-scope switch
 * is opened globally, halting every guarded run. (API §7)
 *
 * This is the incident-response control: the next guarded step for the
 * scoped key hard-halts before any work or spend.
 */
final class AgentGuardKillCommand extends Command
{
    protected $signature = 'agent-guard:kill {--tenant= : The tenant id to halt (opens the tenant kill switch)}';

    protected $description = 'Open the agent-guard kill switch (incident response)';

    public function handle(): int
    {
        $tenant = $this->option('tenant');

        if (! is_string($tenant) || $tenant === '') {
            $opened = AgentGuard::kill('__global_run__');

            $this->info(
                $opened
                    ? 'Kill switch opened for the run scope (global halt).'
                    : 'Run-scope kill switch was already open.'
            );

            return self::SUCCESS;
        }

        $opened = AgentGuard::kill($tenant);

        $this->info(
            $opened
                ? "Kill switch opened for tenant [{$tenant}]. All guarded runs for this tenant will halt."
                : "Kill switch for tenant [{$tenant}] was already open."
        );

        return self::SUCCESS;
    }
}
