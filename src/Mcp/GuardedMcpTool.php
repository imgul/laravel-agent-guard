<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Mcp;

use Closure;
use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Exceptions\BudgetExceededException;
use Imgul\AgentGuard\Loop\LoopFinding;
use Imgul\AgentGuard\Loop\ToolCall;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Support\TextUsage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Guard-rails trait for `Laravel\Mcp\Server\Tool` (PRD FR-4.1, ARCHITECTURE §5).
 *
 * Drops onto any MCP tool and wraps its invocation with the same three
 * controls that guard AI-SDK agent steps — tenant budget, loop detector, and
 * kill switch — so one policy governs both surfaces.
 *
 * The SDK's `ToolInvoker` resolves the tool and calls `[$tool, 'handle']`
 * through the container, which injects `Laravel\Mcp\Request`. Because a
 * class method always overrides a trait method, this trait **owns**
 * `handle()` (the guarded entry point) and delegates the actual work to
 * `run()`, which the consuming tool overrides:
 *
 *   use Imgul\AgentGuard\Mcp\GuardedMcpTool;
 *   use Laravel\Mcp\Server\Tool;
 *
 *   class QueryWarehouseTool extends Tool
 *   {
 *       use GuardedMcpTool;
 *
 *       protected function guardBudget(): array
 *       {
 *           return ['tool_daily_calls' => 100, 'max_repeats' => 3];
 *       }
 *
 *       public function run(Request $request): Response
 *       {
 *           return Response::text($this->queryWarehouse($request));
 *       }
 *   }
 *
 * Correlation is **principal-based** — the authenticated user id, an OAuth
 * client id, or a tenant id derived from the request — never the
 * `MCP-Session-Id` header. Stateless MCP is safe: each call is an
 * independent JSON-RPC request, and the loop detector's sliding window is
 * memoized per tool+principal on the shared `GuardRuntime`, so repeat
 * calls across stateless requests still trip the breaker.
 *
 * The `guardBudget()` return shape:
 *   - 'tool_daily_calls' => int   daily call-count cap for this tool
 *   - 'max_repeats'      => int   loop-detector repeat threshold override
 *   - 'tool_usd'         => [soft, hard]  optional USD cap
 *   - 'tool_tokens'      => [soft, hard]  optional token cap
 *
 * Default behaviour is observe mode: detection and events run, but the
 * action ladder (breaker trip, budget denial) is inert unless enforce() is
 * set.
 */
trait GuardedMcpTool
{
    /**
     * The shared runtime (kill switch, breaker, ledger, estimator).
     */
    private ?GuardRuntime $guardRuntime = null;

    /**
     * Cached BudgetGuard for USD/token tool budgets.
     */
    private ?BudgetGuard $guardBudgetGuard = null;

    /**
     * Resolved guardBudget() config, cached after first read.
     *
     * @var array<string, mixed>|null
     */
    private ?array $guardBudgetConfig = null;

    private bool $guardEnforce = false;

    private bool $guardKillSwitchEnabled = true;

    /** @var array<string, true> */
    private array $guardExemptTools = [];

    /**
     * @var Closure(Request):string
     */
    private Closure $guardPrincipalResolver;

    /**
     * Principal (user/client/tenant id) resolved for the current call.
     */
    private ?string $guardPrincipal = null;

    /**
     * The stable scope key for kill-switch / breaker / budget correlation.
     */
    private ?string $guardScopeKey = null;

    /**
     * Whether the last reserve() reserved a call-count unit.
     */
    private bool $guardReservedCalls = false;

    /**
     * The guarded entry point invoked by the SDK's ToolInvoker.
     *
     * Pipeline (mirrors GuardAgentSteps::handle()):
     *   1. resolve principal + scope key (principal-based, never session id)
     *   2. kill-switch check -> Response::error() if open
     *   3. circuit-breaker check -> Response::error() if OPEN
     *   4. budget reservation (tool_daily_calls + optional USD/token caps)
     *   5. loop detection over the incoming tool arguments
     *   6. run the tool logic via run()
     *   7. reconcile usage + enforce loop findings (breaker trip)
     *
     * @return Response|iterable<mixed>
     */
    public function handle(Request $request): Response|iterable
    {
        $runtime = $this->guardRuntimeInstance();

        $this->guardPrincipal = $this->guardResolvePrincipal($request);
        $scopeKey = $this->guardScopeKey();
        $this->guardScopeKey = $scopeKey;

        // 1. Kill switch: an open switch hard-halts before any work.
        if ($this->guardKillSwitchEnabled
            && $runtime->killSwitch->isOpenFor('tool', $scopeKey)) {
            return Response::error('Tool invocation halted: kill switch is open.');
        }

        // 2. Circuit breaker: an OPEN breaker skips the tool entirely.
        if ($runtime->breaker->isOpen($scopeKey)) {
            return Response::error('Tool invocation halted: circuit breaker is open.');
        }

        // 3. Budget reservation (pessimistic) against the tool's caps.
        try {
            $this->guardReserve($runtime, $request);
        } catch (BudgetExceededException $e) {
            return Response::error($e->getMessage());
        }

        // 4. Loop detection over the incoming tool-call arguments.
        $findings = $this->guardDetectLoops($runtime, $request);

        // 5. Run the tool logic, then reconcile + enforce.
        try {
            $result = $this->run($request);
        } finally {
            $this->guardReconcile($runtime, $request);
        }

        // 6. Enforce: a loop finding trips the breaker for this scope.
        if ($this->guardEnforce && $findings !== []) {
            $runtime->breaker->trip($scopeKey);
        }

        return $result;
    }

    /**
     * The actual tool logic. Override this in the consuming tool — the
     * trait's handle() guards around it.
     *
     * @return Response|iterable<mixed>
     */
    protected function run(Request $request): Response|iterable
    {
        return Response::text('');
    }

    /**
     * The tool's guard configuration. Override to set per-tool caps.
     *
     * @return array<string, mixed>
     */
    protected function guardBudget(): array
    {
        return [];
    }

    // -----------------------------------------------------------------
    // Fluent configuration (mirrors GuardAgentSteps)
    // -----------------------------------------------------------------

    /**
     * Switch to enforce mode: the action ladder (breaker trip, budget
     * denial) actively changes execution. Default is observe.
     */
    public function guardEnforce(): self
    {
        $this->guardEnforce = true;

        return $this;
    }

    /**
     * Switch to observe mode (detection + events only, no enforcement).
     */
    public function guardObserve(): self
    {
        $this->guardEnforce = false;

        return $this;
    }

    /**
     * Enable or disable the kill switch. Default enabled.
     */
    public function guardKillSwitch(bool $enabled = true): self
    {
        $this->guardKillSwitchEnabled = $enabled;

        return $this;
    }

    /**
     * Tools exempt from loop detection (e.g. read-only, idempotent tools).
     *
     * @param  list<string>  $tools
     */
    public function guardExemptTools(array $tools): self
    {
        $this->guardExemptTools = array_fill_keys($tools, true);

        return $this;
    }

    /**
     * Configure the principal resolver used for correlation. Accepts a
     * closure or a service-container binding name. The resolved value is
     * the user_id / OAuth client_id / tenant id — never the session id.
     *
     * @param  Closure(Request):string|string  $resolver
     */
    public function forPrincipal(Closure|string $resolver): self
    {
        $this->guardPrincipalResolver = $resolver instanceof Closure
            ? $resolver
            : fn (Request $request): string => (string) app($resolver);

        return $this;
    }

    // -----------------------------------------------------------------
    // Pipeline stages
    // -----------------------------------------------------------------

    /**
     * Resolve the shared GuardRuntime from the container (cached).
     */
    private function guardRuntimeInstance(): GuardRuntime
    {
        return $this->guardRuntime ??= app(GuardRuntime::class);
    }

    /**
     * Resolve the principal (user_id / client_id / tenant id) for the
     * current request. Falls back to the authenticated user, then
     * 'anonymous'. Never uses MCP-Session-Id.
     */
    private function guardResolvePrincipal(Request $request): string
    {
        if (isset($this->guardPrincipalResolver)) {
            try {
                $principal = ($this->guardPrincipalResolver)($request);
            } catch (\Throwable) {
                $principal = null;
            }

            if (is_string($principal) && $principal !== '') {
                return $principal;
            }
        }

        // Default: the authenticated user's identifier (guarded — auth
        // may not be configured in a stateless/test context).
        try {
            $user = $request->user();
        } catch (\Throwable) {
            return 'anonymous';
        }

        if ($user !== null) {
            $id = $user->getAuthIdentifier();

            if (is_string($id) && $id !== '') {
                return $id;
            }

            if (is_int($id)) {
                return (string) $id;
            }
        }

        return 'anonymous';
    }

    /**
     * A stable per-tool+principal key for breaker/kill-switch scopes.
     * Principal-based, so it survives stateless MCP requests.
     */
    private function guardScopeKey(): string
    {
        $toolName = $this->name();
        $principal = $this->guardPrincipal ?? 'anonymous';

        return 'tool:'.$toolName.':'.$principal;
    }

    /**
     * Reserve budget against the tool's configured caps before the call.
     *
     * The primary cap is `tool_daily_calls` (a call-count budget on the
     * Tool scope, `calls` unit) — each invocation reserves 1.0 call.
     * Optional `tool_usd` / `tool_tokens` caps use the BudgetGuard with
     * an estimate derived from the argument payload.
     *
     * @throws BudgetExceededException in enforce mode when a hard cap is breached.
     */
    private function guardReserve(GuardRuntime $runtime, Request $request): void
    {
        $config = $this->guardBudgetConfig();

        $callsValue = $config['tool_daily_calls'] ?? 0;
        $calls = is_numeric($callsValue) ? (int) $callsValue : 0;

        if ($calls > 0) {
            $this->guardReserveCalls($runtime, $calls);
        }

        $this->guardReserveTokens($runtime, $config, $request);
    }

    /**
     * Reserve one call against the tool's daily call-count cap.
     *
     * Uses the Ledger directly (the `calls` unit is not a token/USD unit,
     * so BudgetGuard's token-based reconciliation does not apply). The
     * reservation is exact: one call == 1.0, so reconciliation releases
     * no excess.
     */
    private function guardReserveCalls(GuardRuntime $runtime, int $calls): void
    {
        $keyParts = ['calls', $this->guardPrincipal ?? 'anonymous', date('Y-m-d')];

        $reservation = new BudgetReservation(
            scope: BudgetScope::Tool,
            keyParts: $keyParts,
            cost: 1.0,
            softCap: 0.0,
            hardCap: (float) $calls,
            ttlSeconds: $this->guardTtlUntilTomorrow(),
        );

        $result = $runtime->ledger->reserve($reservation);

        if ($result->isDenied() && $this->guardEnforce) {
            throw new BudgetExceededException(
                scope: BudgetScope::Tool->value,
                key: $result->key,
                spent: $result->spentAfter,
                cap: (float) $calls,
            );
        }

        // Only flag for reconciliation when the reservation was allowed
        // (a denied reservation was never recorded in the ledger).
        if (! $result->isDenied()) {
            $this->guardReservedCalls = true;
        }
    }

    /**
     * Reserve estimated USD/token cost against optional tool caps.
     *
     * @param  array<string, mixed>  $config
     */
    private function guardReserveTokens(GuardRuntime $runtime, array $config, Request $request): void
    {
        $usdCap = $config['tool_usd'] ?? null;
        $tokenCap = $config['tool_tokens'] ?? null;

        if ($usdCap === null && $tokenCap === null) {
            return;
        }

        $budget = $this->guardBudgetGuardInstance($runtime);

        // Estimate cost from the argument payload size (no model usage is
        // reported by an MCP tool call, so the estimate is the reservation).
        $inputTokens = $this->guardEstimateInputTokens($runtime, $request);

        foreach (['tool_usd' => BudgetGuard::UNIT_USD, 'tool_tokens' => BudgetGuard::UNIT_TOKENS] as $key => $unit) {
            $cap = $config[$key] ?? null;

            if ($cap === null) {
                continue;
            }

            if (! is_array($cap)) {
                continue;
            }

            $soft = (float) ($cap[0] ?? 0.0);
            $hard = (float) ($cap[1] ?? 0.0);

            $budget->cap(BudgetScope::Tool, $soft, $hard, $unit);

            $value = $unit === BudgetGuard::UNIT_TOKENS
                ? (float) $runtime->estimator->estimateTokens($inputTokens)
                : $runtime->estimator->estimateUsd('mcp-tool', $inputTokens);

            if ($value <= 0) {
                continue;
            }

            $keyParts = [$unit, $this->guardPrincipal ?? 'anonymous', date('Y-m-d')];

            $budget->reserve(BudgetScope::Tool, $keyParts, $value, $unit);
        }
    }

    /**
     * Detect loops over the incoming tool-call arguments.
     *
     * @return list<LoopFinding>
     */
    private function guardDetectLoops(GuardRuntime $runtime, Request $request): array
    {
        $args = $request->all();

        $call = new ToolCall(tool: $this->name(), args: $args);

        $inputTokens = $runtime->estimator->estimateInputTokensFromChars(
            strlen(json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        );

        $config = $this->guardBudgetConfig();
        $maxRepeatsValue = $config['max_repeats'] ?? null;
        $maxRepeats = is_numeric($maxRepeatsValue) ? (int) $maxRepeatsValue : null;

        $detector = $runtime->loopDetectorFor(
            $this->guardScopeKey(),
            $this->guardExemptTools,
            $maxRepeats,
        );

        return $detector->check($call, $inputTokens);
    }

    /**
     * Reconcile the pessimistic reservations with actual usage after the
     * tool runs. For call-count budgets the reservation is exact (1.0 ==
     * 1.0); for USD/token budgets the estimate stands (no model usage is
     * reported by an MCP tool call).
     */
    private function guardReconcile(GuardRuntime $runtime, Request $request): void
    {
        $config = $this->guardBudgetConfig();

        // Call-count: the reservation is exact, release any excess (none).
        if ($this->guardReservedCalls) {
            $keyParts = ['calls', $this->guardPrincipal ?? 'anonymous', date('Y-m-d')];

            $reservation = new BudgetReservation(
                scope: BudgetScope::Tool,
                keyParts: $keyParts,
                cost: 1.0,
                softCap: 0.0,
                hardCap: 0.0,
                ttlSeconds: $this->guardTtlUntilTomorrow(),
            );

            $runtime->ledger->reconcile($reservation, 1.0);

            $this->guardReservedCalls = false;
        }

        // USD/token: reconcile the estimate against the estimate (no-op
        // release). The reservation already reflects the call's cost.
        if (($config['tool_usd'] ?? null) !== null || ($config['tool_tokens'] ?? null) !== null) {
            $budget = $this->guardBudgetGuardInstance($runtime);
            $inputTokens = $this->guardEstimateInputTokens($runtime, $request);

            foreach (['tool_usd' => BudgetGuard::UNIT_USD, 'tool_tokens' => BudgetGuard::UNIT_TOKENS] as $key => $unit) {
                if (($config[$key] ?? null) === null) {
                    continue;
                }

                $keyParts = [$unit, $this->guardPrincipal ?? 'anonymous', date('Y-m-d')];
                $reserved = $unit === BudgetGuard::UNIT_TOKENS
                    ? (float) $runtime->estimator->estimateTokens($inputTokens)
                    : $runtime->estimator->estimateUsd('mcp-tool', $inputTokens);

                $budget->reconcile(
                    BudgetScope::Tool,
                    $keyParts,
                    $reserved,
                    new TextUsage(inputTokens: $inputTokens),
                    1.0,
                    $unit,
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The cached guardBudget() config.
     *
     * @return array<string, mixed>
     */
    private function guardBudgetConfig(): array
    {
        if ($this->guardBudgetConfig === null) {
            $this->guardBudgetConfig = $this->guardBudget();
        }

        return $this->guardBudgetConfig;
    }

    /**
     * A BudgetGuard wired to the runtime's ledger with the runtime's
     * enforce flag (cached).
     */
    private function guardBudgetGuardInstance(GuardRuntime $runtime): BudgetGuard
    {
        return $this->guardBudgetGuard ??= new BudgetGuard(
            ledger: $runtime->ledger,
            enforce: $this->guardEnforce,
            dispatcher: $runtime->dispatcher,
        );
    }

    /**
     * Estimate input tokens from the request's argument payload.
     */
    private function guardEstimateInputTokens(GuardRuntime $runtime, Request $request): int
    {
        $args = $request->all();

        return $runtime->estimator->estimateInputTokensFromChars(
            strlen(json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        );
    }

    /**
     * Seconds until the end of the current day (UTC) — the daily TTL for
     * call-count and token/USD tool budgets.
     */
    private function guardTtlUntilTomorrow(): int
    {
        return max(1, (int) (strtotime('tomorrow') - time()));
    }
}
