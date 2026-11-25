<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Imgul\AgentGuard\Budget\BudgetGuard;
use Imgul\AgentGuard\Budget\BudgetScope;
use Imgul\AgentGuard\Downshift\DownshiftEngine;
use Imgul\AgentGuard\Downshift\DownshiftTier;
use Imgul\AgentGuard\Events\ModelDownshifted;
use Imgul\AgentGuard\Loop\CircuitBreaker;
use Imgul\AgentGuard\Loop\KillSwitch;
use Imgul\AgentGuard\Loop\LoopDetector;
use Imgul\AgentGuard\Loop\LoopState;
use Imgul\AgentGuard\Loop\ToolCall;
use Imgul\AgentGuard\Support\Similarity\SimilarityStrategy;
use Imgul\AgentGuard\Support\Similarity\SimHash;
use Imgul\AgentGuard\Support\Similarity\TrigramJaccard;
use Imgul\AgentGuard\Support\StepEstimate;
use Imgul\AgentGuard\Support\TextUsage;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\PendingStep;
use Stringable;

/**
 * PendingStep-aware middleware: the step-level wedge for laravel/ai 1.x
 * (PRD FR-3.1, ARCHITECTURE §3, §6).
 *
 * Wraps every generation step of a guarded agent and applies, in order:
 *   1. kill-switch check (global, tenant, or run scope)
 *   2. circuit-breaker check (skip step while OPEN, allow probe after cooldown)
 *   3. budget reservation (pessimistic estimate; soft-cap -> downshift the
 *      step that crosses it; hard-cap -> BudgetExceededException in enforce)
 *   4. downshift application (model swap + tool drop + token tightening)
 *   5. the step itself, then usage reconciliation and loop detection
 *
 * Each agent run gets a unique run id (generated on the first step via
 * PendingStep::isFirstStep()). The breaker and kill switch are keyed by
 * that run id, so a loop in one run never blocks an independent run.
 *
 * Default behaviour is observe mode: detection and events run, but the
 * action ladder (downshift, break, kill) is inert unless enforce() is set.
 *
 * Construct via the fluent make() factory:
 *
 *   GuardAgentSteps::make()
 *       ->forTenant(fn () => Auth::id())
 *       ->budget(['run' => [1.00, 2.00], 'tenant' => [5.00, 8.00]])
 *       ->downshiftAt(0.80, toModel: 'claude-haiku-4-5', withoutTools: ['DeepWebSearch'])
 *       ->breakLoops(maxRepeats: 3, frequency: 8)
 *       ->enforce();
 *
 * One instance serves the steps of a single agent run; call reset() to start
 * a fresh run (clears the run id, loop window, and downshift tracking).
 */
final class GuardAgentSteps
{
    /**
     * @var  Closure(void): string|int|Stringable|null
     */
    private Closure $tenantResolver;

    /**
     * @var array<string, array<string, array{soft: float, hard: float}>>
     */
    private array $budgetCaps = [];

    /**
     * @var list<DownshiftTier>
     */
    private array $tiers = [];

    private bool $enforce = false;

    private bool $killSwitchEnabled = true;

    private string $similarityStrategy = 'simhash';

    /** @var array<string, true> */
    private array $exemptTools = [];

    private int $maxRepeats = 3;

    private float $maxStepGrowthRatio = 2.5;

    private int $windowSize = 50;

    private int $frequencyThreshold = 8;

    /** Whether the tenant resolver produced an uncastable value (warned once). */
    private bool $tenantWarningLogged = false;

    private ?GuardRuntime $runtime = null;

    private ?LoopDetector $detector = null;

    private ?BudgetGuard $budget = null;

    private ?DownshiftEngine $downshift = null;

    /** Unique run id, generated on the first step. */
    private ?string $runId = null;

    /** Current effective model (downshifted); null = defer to the step's model. */
    private ?string $activeModel = null;

    /** The model this step was actually sent with (for reconciliation). */
    private string $sentModel = '';

    /**
     * Reserved amounts from the last reserve() call, keyed by scope:unit.
     * Used by reconcile() to release the pessimistic excess.
     *
     * @var array<string, float>
     */
    private array $reservedAmounts = [];

    /**
     * Factory: build a middleware. When no runtime is given the service
     * container resolves one via the AgentGuard runtime binding.
     */
    public static function make(?GuardRuntime $runtime = null): self
    {
        $middleware = new self;

        $middleware->runtime = $runtime;

        return $middleware;
    }

    /**
     * Resolve the tenant id from the authenticated principal. Required for
     * tenant-scoped budgets; without it tenant scopes are skipped.
     *
     * Accepts strings, integers, and Stringable objects. Any other type
     * logs a warning (once per run) and returns null.
     */
    public function forTenant(Closure|string $resolver): self
    {
        $this->tenantResolver = $resolver instanceof Closure
            ? $resolver
            : fn () => app($resolver);

        return $this;
    }

    /**
     * Configure budget caps. Keys are scope names ('run', 'agent',
     * 'tenant', 'tool'); values are [softCap, hardCap]. Each scope may be
     * given in 'usd' or 'tokens' via the third element (default usd).
     *
     * @param  array<string, array<int, float|string>>  $caps
     */
    public function budget(array $caps): self
    {
        foreach ($caps as $scopeName => $cap) {
            $scope = $scopeName !== ''
                ? self::parseScope($scopeName)
                : BudgetScope::Run;

            $soft = (float) ($cap[0] ?? 0.0);
            $hard = (float) ($cap[1] ?? 0.0);
            $unit = (string) ($cap[2] ?? BudgetGuard::UNIT_USD);

            $this->budgetCaps[$scope->value][$unit] = ['soft' => $soft, 'hard' => $hard];
        }

        return $this;
    }

    /**
     * Add a rung to the downshift ladder. Chainable for a multi-tier ladder;
     * tiers fire in order of threshold (lowest spend fraction first).
     *
     * @param  list<string>  $withoutTools
     */
    public function downshiftAt(
        float $threshold,
        string $toModel,
        array $withoutTools = [],
        ?int $withMaxTokens = null,
    ): self {
        $this->tiers[] = new DownshiftTier(
            threshold: $threshold,
            toModel: $toModel,
            withoutTools: $withoutTools,
            maxTokens: $withMaxTokens,
        );

        return $this;
    }

    /**
     * Configure loop-break thresholds. The optional frequency parameter
     * overrides the config default (agent-guard.loop.frequency_threshold).
     * When omitted, the value is read from config at runtime.
     */
    public function breakLoops(int $maxRepeats = 3, float $maxStepGrowthRatio = 2.5, int $windowSize = 50, ?int $frequency = null): self
    {
        $this->maxRepeats = $maxRepeats;
        $this->maxStepGrowthRatio = $maxStepGrowthRatio;
        $this->windowSize = $windowSize;

        if ($frequency !== null) {
            $this->frequencyThreshold = $frequency;
        } else {
            $configured = config('agent-guard.loop.frequency_threshold', 8);
            $this->frequencyThreshold = is_numeric($configured) ? (int) $configured : 8;
        }

        return $this;
    }

    /**
     * Switch to enforce mode: the action ladder (downshift, break, kill)
     * actively changes execution. Default is observe (events only).
     */
    public function enforce(): self
    {
        $this->enforce = true;

        return $this;
    }

    /**
     * Switch to observe mode (detection + events only, no enforcement).
     */
    public function observe(): self
    {
        $this->enforce = false;

        return $this;
    }

    /**
     * Enable or disable the kill switch. Default enabled.
     */
    public function killSwitch(bool $enabled = true): self
    {
        $this->killSwitchEnabled = $enabled;

        return $this;
    }

    /**
     * Tools exempt from loop detection (e.g. read-only, idempotent tools).
     *
     * @param  list<string>  $tools
     */
    public function exemptTools(array $tools): self
    {
        $this->exemptTools = array_fill_keys($tools, true);

        return $this;
    }

    /**
     * Similarity strategy for semantic loop detection:
     * 'none' | 'simhash' | 'trigram' | a SimilarityStrategy class.
     */
    public function similarity(string $strategy): self
    {
        // Resolved when the detector is built; stored as a flag for the
        // runtime to honour (the runtime owns the concrete strategy).
        $this->similarityStrategy = $strategy;

        return $this;
    }

    /**
     * The current run id, or null before the first step.
     */
    public function runId(): ?string
    {
        return $this->runId;
    }

    /**
     * Middleware entry point (laravel/ai HasMiddleware pipe).
     *
     * @param  Closure(PendingStep): StepResult  $next
     * @return StepResult
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $runtime = $this->resolveRuntime();

        $tenantId = $this->resolveTenant();

        $runId = $this->ensureRunId();

        // 1. Kill switch: check global, tenant, and run scopes.
        if ($this->killSwitchEnabled) {
            $killSwitch = $this->killSwitchInstance($runtime);

            if ($killSwitch->isGloballyOpen()
                || ($tenantId !== null && $killSwitch->isOpenForTenant($tenantId))
                || $killSwitch->isOpen('run', $runId)) {
                return StepResult::stop();
            }
        }

        // 2. Circuit breaker: an OPEN breaker halts, but allows one probe
        //    step through after the cooldown elapses.
        $breaker = $this->breakerInstance($runtime);

        if ($breaker->isOpen($runId)) {
            if (! $breaker->allowProbe($runId)) {
                return StepResult::stop();
            }
            // Probe allowed — the breaker is now HALF_OPEN. Let this step
            // through; afterStep() will call probeResult() based on findings.
        }

        $budget = $this->budgetInstance($runtime);

        // 3. Budget reservation (pessimistic) against every configured scope.
        $this->reserve($budget, $tenantId, $step);

        // 4. Apply downshift to this step if a soft cap was crossed.
        $step = $this->applyDownshift($step, $budget, $tenantId);

        // 5. Run the step, then reconcile usage and detect loops.
        $result = $next($step);

        $this->afterStep($budget, $result, $tenantId, $runId, $breaker);

        return $result;
    }

    /**
     * Reset per-run state: run id, loop window, and downshift tracking.
     */
    public function reset(): void
    {
        $this->runId = null;
        $this->detector = null;
        $this->activeModel = null;
        $this->sentModel = '';
        $this->tenantWarningLogged = false;
    }

    // -----------------------------------------------------------------
    // Runtime wiring (private accessors, distinct from fluent setters)
    // -----------------------------------------------------------------

    private function resolveRuntime(): GuardRuntime
    {
        if ($this->runtime !== null) {
            return $this->runtime;
        }

        $this->runtime = app(GuardRuntime::class);

        return $this->runtime;
    }

    private function detectorInstance(GuardRuntime $runtime): LoopDetector
    {
        if ($this->detector === null) {
            $this->detector = new LoopDetector(
                windowSize: $this->windowSize,
                maxRepeats: $this->maxRepeats,
                maxStepGrowthRatio: $this->maxStepGrowthRatio,
                frequencyThreshold: $this->frequencyThreshold,
                similarity: $this->resolveSimilarity($runtime),
                similarityThreshold: 0.85,
                exemptTools: $this->exemptTools,
                dispatcher: $runtime->dispatcher,
            );
        }

        return $this->detector;
    }

    /**
     * Resolve the configured similarity strategy to a SimilarityStrategy
     * instance (or null for 'none'), falling back to the runtime's.
     */
    private function resolveSimilarity(GuardRuntime $runtime): ?SimilarityStrategy
    {
        $strategy = $this->similarityStrategy;

        if ($strategy === 'none') {
            return null;
        }

        if ($strategy === 'simhash') {
            return new SimHash();
        }

        if ($strategy === 'trigram') {
            return new TrigramJaccard();
        }

        // A fully-qualified SimilarityStrategy class name.
        if (class_exists($strategy) && is_subclass_of($strategy, SimilarityStrategy::class)) {
            return new $strategy;
        }

        return $runtime->similarity;
    }

    private function breakerInstance(GuardRuntime $runtime): CircuitBreaker
    {
        return $runtime->breaker;
    }

    private function killSwitchInstance(GuardRuntime $runtime): KillSwitch
    {
        return $runtime->killSwitch;
    }

    private function budgetInstance(GuardRuntime $runtime): BudgetGuard
    {
        if ($this->budget === null) {
            // The middleware's own enforce/observe mode governs the budget
            // guard (overriding the runtime's global default).
            $guard = new BudgetGuard(
                ledger: $runtime->ledger,
                enforce: $this->enforce,
                dispatcher: $runtime->dispatcher,
            );

            foreach ($this->budgetCaps as $scope => $units) {
                foreach ($units as $unit => $cap) {
                    $guard->cap(self::parseScope($scope), $cap['soft'], $cap['hard'], $unit);
                }
            }

            $this->budget = $guard;
        }

        return $this->budget;
    }

    private function downshiftInstance(GuardRuntime $runtime): DownshiftEngine
    {
        if ($this->downshift === null) {
            $this->downshift = $runtime->downshiftEngine($this->tiers);
        }

        return $this->downshift;
    }

    // -----------------------------------------------------------------
    // Pipeline stages
    // -----------------------------------------------------------------

    /**
     * Ensure the run id is set. Generated on the first step; if the
     * middleware is attached mid-run, a new id is generated immediately.
     */
    private function ensureRunId(): string
    {
        if ($this->runId === null) {
            $this->runId = bin2hex(random_bytes(8));
        }

        return $this->runId;
    }

    /**
     * Resolve the tenant id (null when no resolver is configured or the
     * value is uncastable). Accepts strings, integers, and Stringable.
     */
    private function resolveTenant(): ?string
    {
        if (! isset($this->tenantResolver)) {
            return null;
        }

        try {
            $tenant = ($this->tenantResolver)();
        } catch (\Throwable) {
            return null;
        }

        if (is_string($tenant) && $tenant !== '') {
            return $tenant;
        }

        if (is_int($tenant)) {
            return (string) $tenant;
        }

        if ($tenant instanceof Stringable) {
            $cast = (string) $tenant;

            return $cast !== '' ? $cast : null;
        }

        if (! $this->tenantWarningLogged) {
            Log::warning(
                'agent-guard: forTenant() resolver returned an uncastable value',
                ['type' => get_debug_type($tenant)],
            );
            $this->tenantWarningLogged = true;
        }

        return null;
    }

    /**
     * Reserve estimated cost against the configured scopes before the step.
     * The estimate is derived from the step's message content so the
     * pessimistic reservation reflects the actual request size.
     */
    private function reserve(BudgetGuard $budget, ?string $tenantId, PendingStep $step): void
    {
        if ($this->budgetCaps === []) {
            return;
        }

        $estimate = $this->estimateForStep($step);

        if ($estimate === null) {
            return;
        }

        $this->reservedAmounts = [];

        foreach ($this->budgetCaps as $scope => $units) {
            $keyParts = $this->keyPartsFor($scope, $tenantId);

            foreach ($units as $unit => $cap) {
                // Skip tenant scopes without a tenant context (can't key them).
                if ($scope === 'tenant' && $tenantId === null) {
                    continue;
                }

                $value = $unit === BudgetGuard::UNIT_TOKENS
                    ? (float) $estimate->totalTokens()
                    : $estimate->usd;

                if ($value <= 0) {
                    continue;
                }

                $this->reservedAmounts[$scope.':'.$unit] = $value;

                $budget->reserve(self::parseScope($scope), $keyParts, $value, $unit);
            }
        }
    }

    /**
     * Apply the deepest applicable downshift tier to the step.
     */
    private function applyDownshift(PendingStep $step, BudgetGuard $budget, ?string $tenantId): PendingStep
    {
        // In observe mode the action ladder is inert: detection and events
        // only, the step runs unchanged.
        if (! $this->enforce || $this->tiers === []) {
            $this->sentModel = $step->model;

            return $step;
        }

        $fraction = $this->spendFraction($budget, $tenantId);

        if ($fraction <= 0) {
            $this->sentModel = $step->model;

            return $step;
        }

        $effectiveModel = $this->activeModel ?? $step->model;
        $tier = $this->downshiftInstance($this->resolveRuntime())->applicableFor($fraction, $effectiveModel);

        if ($tier === null) {
            $this->sentModel = $step->model;

            return $step;
        }

        // Capability-cliff mitigation: swap model, drop tools, tighten tokens.
        $fromModel = $effectiveModel;

        $step = $step->withModel($tier->toModel);

        if ($tier->withoutTools !== []) {
            $step = $step->withoutTools(...$tier->withoutTools);
        }

        if ($tier->maxTokens !== null) {
            $step = $step->withMaxTokens($tier->maxTokens);
        }

        $this->activeModel = $tier->toModel;
        $this->sentModel = $tier->toModel;

        $tierIndex = array_search($tier, $this->tiers, true);

        $this->dispatch(new ModelDownshifted(
            fromModel: $fromModel,
            toModel: $tier->toModel,
            tier: $tierIndex === false ? null : $tierIndex,
            reason: 'budget-soft-cap',
        ));

        return $step;
    }

    /**
     * Post-step work: reconcile usage, then loop-detect the tool calls.
     * If the breaker was in HALF_OPEN (probe), records the probe result.
     */
    private function afterStep(BudgetGuard $budget, StepResult $result, ?string $tenantId, string $runId, CircuitBreaker $breaker): void
    {
        // Reconcile pessimistic reservations with exact usage.
        $this->reconcile($budget, $result, $tenantId);

        // Loop detection over the tool calls returned by the step.
        $response = $result->response();

        if ($response === null) {
            // No response — if probing, treat as a successful probe.
            if ($breaker->shouldPruneContext($runId)) {
                $breaker->probeResult($runId, true);
            }

            return;
        }

        $inputTokens = $this->estimateInputTokens($response->text);

        $findings = [];

        foreach ($response->toolCalls as $toolCall) {
            /** @var array<string, mixed> $args */
            $args = $toolCall->arguments;

            $call = new ToolCall(tool: $toolCall->name, args: $args);

            $findings = array_merge($findings, $this->detectorInstance($this->resolveRuntime())->check(
                $call,
                $inputTokens,
            ));
        }

        // Enforce: a loop finding trips the breaker for the run.
        if ($this->enforce && $findings !== []) {
            if ($breaker->shouldPruneContext($runId)) {
                // Probe failed — reopen the breaker.
                $breaker->probeResult($runId, false);
            } else {
                $breaker->trip($runId);
            }
        } elseif ($breaker->shouldPruneContext($runId)) {
            // Probe succeeded — close the breaker.
            $breaker->probeResult($runId, true);
        }
    }

    /**
     * Reconcile the pessimistic reservation with the exact usage.
     */
    private function reconcile(BudgetGuard $budget, StepResult $result, ?string $tenantId): void
    {
        if ($this->budgetCaps === []) {
            return;
        }

        $response = $result->response();

        if ($response === null) {
            return;
        }

        /** @var array<string, mixed> $usageData */
        $usageData = $response->usage->toArray();

        $usage = TextUsage::fromArray($usageData);
        $model = $this->sentModel !== '' ? $this->sentModel : ($this->activeModel ?? '');

        foreach ($this->budgetCaps as $scope => $units) {
            $keyParts = $this->keyPartsFor($scope, $tenantId);

            foreach ($units as $unit => $cap) {
                if ($scope === 'tenant' && $tenantId === null) {
                    continue;
                }

                // Release the pessimistic reservation against the exact
                // usage. The reserved amount is the pre-step estimate; the
                // actual is the exact USD (pricing catalog) or token count.
                $reserved = $this->reservedAmounts[$scope.':'.$unit]
                    ?? $this->estimateForModel($model, $usage, $unit);

                $totalTokens = max(1, $usage->totalTokens());
                $actualUsd = $this->resolveRuntime()->estimator->actualUsd($model, $usage);

                // BudgetGuard computes actual = unitCost * tokens / 1000 for
                // USD, so pass the effective price per 1k tokens.
                $unitCost = $unit === BudgetGuard::UNIT_USD
                    ? $actualUsd * 1000.0 / $totalTokens
                    : 1.0;

                $budget->reconcile(
                    self::parseScope($scope),
                    $keyParts,
                    $reserved,
                    $usage,
                    $unitCost,
                    $unit,
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Estimated pre-step cost for a PendingStep: input tokens from message
     * content, a default output allowance, and USD from the pricing catalog.
     *
     * @return StepEstimate|null  null when nothing can be estimated
     */
    private function estimateForStep(PendingStep $step): ?StepEstimate
    {
        $inputTokens = $this->estimateMessageTokens($step->messages);

        if ($inputTokens <= 0) {
            return null;
        }

        $model = $this->activeModel ?? $step->model;
        $estimator = $this->resolveRuntime()->estimator;

        $outputTokens = $estimator->defaultOutputTokens();

        return new StepEstimate(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            usd: $estimator->estimateUsd($model, $inputTokens),
        );
    }

    /**
     * Estimated reservation amount (USD or token count) for a model/usage.
     */
    private function estimateForModel(string $model, TextUsage $usage, string $unit): float
    {
        if ($unit === BudgetGuard::UNIT_TOKENS) {
            return (float) $usage->totalTokens();
        }

        return $this->resolveRuntime()->estimator->actualUsd($model, $usage);
    }

    /**
     * Spend fraction (spent / soft cap) used to pick a downshift tier.
     * Uses the first configured scope that has a positive soft cap.
     */
    private function spendFraction(BudgetGuard $budget, ?string $tenantId): float
    {
        $scope = 'run';

        foreach (['run', 'agent', 'tenant'] as $candidate) {
            if (isset($this->budgetCaps[$candidate])) {
                $scope = $candidate;

                break;
            }
        }

        $softCap = $this->softCapFor($scope);

        if ($softCap <= 0) {
            return 0.0;
        }

        $keyParts = $this->keyPartsFor($scope, $tenantId);

        return $budget->spent(self::parseScope($scope), $keyParts, $this->unitFor($scope)) / $softCap;
    }

    /**
     * Estimate input tokens from the character length of message content.
     *
     * @param  array<int|string, Message|array<string, mixed>|object>  $messages
     */
    private function estimateMessageTokens(array $messages): int
    {
        $chars = 0;

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $chars += is_string($message->content) ? strlen($message->content) : 0;
            } elseif (is_object($message) && isset($message->content)) {
                $content = $message->content;
                $chars += is_string($content) ? strlen($content) : 0;
            } elseif (is_array($message) && isset($message['content'])) {
                $content = $message['content'];
                $chars += is_string($content) ? strlen($content) : 0;
            }
        }

        return $this->resolveRuntime()->estimator->estimateInputTokensFromChars($chars);
    }

    /**
     * Estimate input tokens from the character length of a response text.
     */
    private function estimateInputTokens(string $text): int
    {
        return $this->resolveRuntime()->estimator->estimateInputTokensFromChars(strlen($text));
    }

    private function unitFor(string $scope): string
    {
        return isset($this->budgetCaps[$scope])
            ? (string) array_key_first($this->budgetCaps[$scope])
            : BudgetGuard::UNIT_USD;
    }

    private function softCapFor(string $scope): float
    {
        if (! isset($this->budgetCaps[$scope])) {
            return 0.0;
        }

        $units = $this->budgetCaps[$scope];
        $unit = array_key_first($units);

        return $units[$unit]['soft'] ?? 0.0;
    }

    /**
     * Ledger key parts for a scope, tenant-derived.
     *
     * @return list<string>
     */
    private function keyPartsFor(string $scope, ?string $tenantId): array
    {
        if ($scope === 'tenant') {
            return [$tenantId ?? 'anonymous', date('Y-m-d')];
        }

        if ($scope === 'agent') {
            return ['agent'];
        }

        if ($scope === 'tool') {
            return ['agent'];
        }

        // run scope — keyed by the unique run id.
        return [$this->runId ?? 'run'];
    }

    private static function parseScope(string $scope): BudgetScope
    {
        return match ($scope) {
            'agent' => BudgetScope::Agent,
            'tenant' => BudgetScope::Tenant,
            'tool' => BudgetScope::Tool,
            default => BudgetScope::Run,
        };
    }

    private function dispatch(ModelDownshifted $event): void
    {
        $this->resolveRuntime()->dispatcher?->dispatch($event);
    }
}
