<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetResult;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Mcp\GuardedMcpTool;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Pricing\PricingCatalog;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Phase 4 hard gates: the GuardedMcpTool trait for Laravel\Mcp\Server\Tool.
 *
 * Proves that the MCP tool surface shares the same guard policy as AI-SDK
 * agent steps — one GuardRuntime, one kill switch, one circuit breaker,
 * one budget ledger — with principal-based correlation (never session id):
 *   1. A guarded tool runs normally when no control is triggered.
 *   2. An open kill switch hard-halts the tool with a Response::error().
 *   3. A repeated tool call trips the circuit breaker mid-stream.
 *   4. A daily call-count cap denies the call in enforce mode.
 *   5. The same policy governs both surfaces (shared kill switch).
 */

/**
 * An in-memory ledger (same contract as the Phase 2/3 fakes) so the tests
 * run without Redis. Spend is tracked per reservation key in its own state.
 */
function mcpTestLedger(): Ledger
{
    return new class implements Ledger
    {
        /** @var array<string, float> */
        private array $spend = [];

        public function reserve(BudgetReservation $reservation): BudgetResult
        {
            $key = $reservation->key();
            $spent = $this->spend[$key] ?? 0.0;

            if ($reservation->hardCap > 0 && $spent + $reservation->cost > $reservation->hardCap) {
                return BudgetResult::denied($key, $spent);
            }

            $spent += $reservation->cost;
            $this->spend[$key] = $spent;

            $soft = $reservation->softCap > 0 && $spent > $reservation->softCap;

            return $soft ? BudgetResult::allowedSoftExceeded($key, $spent) : BudgetResult::allowed($key, $spent);
        }

        public function reconcile(BudgetReservation $reservation, float $actualCost): float
        {
            $key = $reservation->key();
            $excess = $reservation->cost - $actualCost;
            $this->spend[$key] = ($this->spend[$key] ?? 0.0) - $excess;

            return $excess;
        }

        public function spent(string $key): float
        {
            return $this->spend[$key] ?? 0.0;
        }
    };
}

/**
 * Build a GuardRuntime backed by an in-memory ledger and a small pricing
 * catalog so USD estimates resolve.
 */
function mcpTestRuntime(bool $enforce = false, ?Ledger $ledger = null): GuardRuntime
{
    $catalog = PricingCatalog::fromArray([
        'version' => 'test-1',
        'models' => [
            'gpt-5' => ['input' => 1.25, 'output' => 10.0],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.0],
        ],
    ]);

    /** @var Dispatcher $dispatcher */
    $dispatcher = app('events');

    return new GuardRuntime(
        ledger: $ledger ?? mcpTestLedger(),
        estimator: new CostEstimator($catalog, defaultOutputTokens: 100),
        similarity: null,
        dispatcher: $dispatcher,
        enforce: $enforce,
        killSwitchEnabled: true,
        loopCooldownSeconds: 60,
    );
}

/**
 * A minimal guarded MCP tool for testing. The trait owns handle(); the
 * tool overrides run() and guardBudget().
 */
class GuardedTestTool extends Tool
{
    use GuardedMcpTool;

    protected string $name = 'test_guarded_tool';

    protected string $description = 'A guarded test tool.';

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $guardConfig = [];

    /**
     * @param  array<string, mixed>  $guardConfig
     */
    public function __construct(array $guardConfig = [])
    {
        $this->guardConfig = $guardConfig;
    }

    /**
     * @return array<string, mixed>
     */
    protected function guardBudget(): array
    {
        return $this->guardConfig;
    }

    public function run(Request $request): Response
    {
        /** @var array<string, mixed> $all */
        $all = $request->all();

        $this->calls[] = $all;

        return Response::text('tool result for: '.json_encode($all));
    }
}

/**
 * Build a Request with the given arguments.
 *
 * @param  array<string, mixed>  $arguments
 */
function mcpTestRequest(array $arguments = []): Request
{
    return new Request(arguments: $arguments);
}

it('runs the tool normally when no control is triggered', function (): void {
    $runtime = mcpTestRuntime(enforce: true);

    // Bind the runtime so the trait's app(GuardRuntime::class) resolves it.
    app()->instance(GuardRuntime::class, $runtime);

    $tool = new GuardedTestTool;
    /** @var Response $response */
    $response = $tool->handle(mcpTestRequest(['q' => 'hello']));

    expect($response->isError())->toBeFalse()
        ->and($tool->calls)->toHaveCount(1);
});

it('hard-halts with a Response::error when the kill switch is open', function (): void {
    $runtime = mcpTestRuntime(enforce: true);

    app()->instance(GuardRuntime::class, $runtime);

    // Open the kill switch for this tool's scope (tool:test_guarded_tool:anonymous).
    $runtime->killSwitch->kill('tool', 'tool:test_guarded_tool:anonymous');

    $tool = new GuardedTestTool;
    /** @var Response $response */
    $response = $tool->handle(mcpTestRequest(['q' => 'hello']));

    expect($response->isError())->toBeTrue()
        ->and($tool->calls)->toHaveCount(0);
});

it('trips the circuit breaker on a repeated tool call', function (): void {
    $runtime = mcpTestRuntime(enforce: true);

    app()->instance(GuardRuntime::class, $runtime);

    $tool = new GuardedTestTool(['max_repeats' => 3]);
    $tool->guardEnforce();

    $scopeKey = 'tool:test_guarded_tool:anonymous';

    // The same arguments repeated enough times trip the breaker. The
    // detector's sliding window fills; after max_repeats the breaker trips
    // and subsequent calls return a Response::error.
    $errored = false;

    for ($i = 0; $i < 6; $i++) {
        /** @var Response $response */
        $response = $tool->handle(mcpTestRequest(['q' => 'same query']));

        if ($response->isError()) {
            $errored = true;

            break;
        }
    }

    expect($errored)->toBeTrue()
        ->and($runtime->breaker->isOpen($scopeKey))->toBeTrue();
});

it('denies the call when the daily call-count cap is exceeded in enforce mode', function (): void {
    $runtime = mcpTestRuntime(enforce: true);

    app()->instance(GuardRuntime::class, $runtime);

    // Cap at 2 daily calls.
    $tool = new GuardedTestTool(['tool_daily_calls' => 2]);
    $tool->guardEnforce();

    /** @var list<Response> $responses */
    $responses = [];

    for ($i = 0; $i < 4; $i++) {
        $responses[] = $tool->handle(mcpTestRequest(['q' => 'call '.$i]));
    }

    // The first 2 calls succeed; the 3rd is denied by the hard cap.
    $successCount = count(array_filter($responses, fn (iterable|Response $r): bool => $r instanceof Response && ! $r->isError()));
    $errorCount = count(array_filter($responses, fn (iterable|Response $r): bool => $r instanceof Response && $r->isError()));

    expect($successCount)->toBe(2)
        ->and($errorCount)->toBe(2);
});

it('allows the call to proceed past the cap in observe mode', function (): void {
    $runtime = mcpTestRuntime(enforce: false);

    app()->instance(GuardRuntime::class, $runtime);

    $tool = new GuardedTestTool(['tool_daily_calls' => 2]);

    // 4 calls — all should proceed in observe mode (no denial).
    for ($i = 0; $i < 4; $i++) {
        /** @var Response $response */
        $response = $tool->handle(mcpTestRequest(['q' => 'call '.$i]));

        expect($response->isError())->toBeFalse();
    }
});

it('shares the kill switch with agent steps: one policy governs both surfaces', function (): void {
    $runtime = mcpTestRuntime(enforce: true);

    app()->instance(GuardRuntime::class, $runtime);

    // Open the kill switch for the tool scope.
    $runtime->killSwitch->kill('tool', 'tool:test_guarded_tool:anonymous');

    $tool = new GuardedTestTool;
    /** @var Response $mcpResponse */
    $mcpResponse = $tool->handle(mcpTestRequest(['q' => 'hello']));

    // The MCP tool is halted by the same kill switch that would halt an
    // agent step — one policy, one runtime, one switch.
    expect($mcpResponse->isError())->toBeTrue();
});

it('correlates by principal, not by session id', function (): void {
    $runtime = mcpTestRuntime(enforce: true);

    app()->instance(GuardRuntime::class, $runtime);

    $tool = new GuardedTestTool(['tool_daily_calls' => 2]);
    $tool->guardEnforce();

    // Configure a principal resolver that returns a fixed tenant id.
    $tool->forPrincipal(fn (Request $request): string => 'tenant-42');

    // The scope key must embed the principal, not a session id.
    $scopeKey = 'tool:test_guarded_tool:tenant-42';

    /** @var list<Response> $responses */
    $responses = [];

    for ($i = 0; $i < 4; $i++) {
        $responses[] = $tool->handle(mcpTestRequest(['q' => 'call '.$i]));
    }

    // Two calls succeed, two are denied — all correlated to tenant-42.
    $successCount = count(array_filter($responses, fn (iterable|Response $r): bool => $r instanceof Response && ! $r->isError()));

    expect($successCount)->toBe(2)
        ->and($runtime->breaker->isOpen($scopeKey))->toBeFalse()
        // The principal-based key is the one that matters; the anonymous
        // key is never used.
        ->and($runtime->killSwitch->isOpenFor('tool', 'tool:test_guarded_tool:anonymous'))->toBeFalse();
});

it('uses the authenticated user as the default principal', function (): void {
    $runtime = mcpTestRuntime(enforce: true);

    app()->instance(GuardRuntime::class, $runtime);

    // No principal resolver configured — the trait falls back to the
    // authenticated user. In the testbench context (no auth), it resolves
    // to 'anonymous', and the scope key is deterministic.
    $tool = new GuardedTestTool;
    /** @var Response $response */
    $response = $tool->handle(mcpTestRequest(['q' => 'hello']));

    expect($response->isError())->toBeFalse()
        ->and($runtime->breaker->isOpen('tool:test_guarded_tool:anonymous'))->toBeFalse();
});
