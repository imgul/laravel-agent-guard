<?php

declare(strict_types=1);

use Imgul\AgentGuard\Budget\BudgetReservation;
use Imgul\AgentGuard\Budget\BudgetResult;
use Imgul\AgentGuard\Budget\Ledger;
use Imgul\AgentGuard\Events\ModelDownshifted;
use Imgul\AgentGuard\Exceptions\BudgetExceededException;
use Imgul\AgentGuard\Middleware\GuardAgentSteps;
use Imgul\AgentGuard\Middleware\GuardRuntime;
use Imgul\AgentGuard\Support\CostEstimator;
use Imgul\AgentGuard\Support\Pricing\PricingCatalog;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage as SdkTextUsage;
use Laravel\Ai\Responses\Data\ToolCall as SdkToolCall;

/**
 * Phase 3 hard gates: the GuardAgentSteps step-level wedge.
 *
 * Three behaviours are proven against the real laravel/ai SDK contracts
 * (PendingStep, StepResult, StepResponse):
 *   1. A 5-step loop of repeated tool calls trips the circuit breaker mid-run.
 *   2. A soft-cap breach downshifts the step's model mid-run + emits the event.
 *   3. A hard-cap breach halts with BudgetExceededException under enforce.
 */

/**
 * An in-memory ledger (same contract as the Phase 2 fake) so the tests run
 * without Redis. Spend is tracked per reservation key in its own state.
 */
function guardTestLedger(): Ledger
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
function guardTestRuntime(bool $enforce = false, ?Ledger $ledger = null): GuardRuntime
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
        ledger: $ledger ?? guardTestLedger(),
        estimator: new CostEstimator($catalog, defaultOutputTokens: 100),
        similarity: null,
        dispatcher: $dispatcher,
        enforce: $enforce,
        killSwitchEnabled: true,
        loopCooldownSeconds: 60,
    );
}

/**
 * Build a PendingStep with the given model and a single user message.
 */
function guardTestStep(string $model = 'gpt-5', int $number = 0, string $text = 'do the thing'): PendingStep
{
    return new PendingStep(
        number: $number,
        isFinalStep: $number >= 4,
        provider: 'openai',
        model: $model,
        instructions: 'You are a test agent.',
        messages: [new Message(MessageRole::User, $text)],
        tools: [],
        schema: null,
        options: null,
        usage: new SdkTextUsage,
    );
}

/**
 * Build a StepResult that wraps a StepResponse with the given tool calls.
 *
 * @param  list<SdkToolCall>  $toolCalls
 */
function guardTestResult(
    string $text = 'ok',
    array $toolCalls = [],
    ?SdkTextUsage $usage = null,
    string $model = 'gpt-5',
): StepResult {
    $response = new StepResponse(
        text: $text,
        toolCalls: $toolCalls,
        finishReason: $toolCalls === [] ? FinishReason::Stop : FinishReason::ToolCalls,
        usage: $usage ?? new SdkTextUsage(inputTokens: 100, outputTokens: 50),
        meta: new Meta(provider: 'openai', model: $model),
    );

    return new StepResult($response);
}

it('trips the circuit breaker on a 5-step loop of repeated tool calls', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->breakLoops(maxRepeats: 3)
        ->enforce();

    $repeatedToolCall = new SdkToolCall(id: 'tc-1', name: 'search', arguments: ['q' => 'same']);

    // The $next closure always returns a result with the same repeated tool
    // call, simulating an agent stuck in a tool-call loop.
    $next = fn (PendingStep $step): StepResult => guardTestResult(toolCalls: [$repeatedToolCall]);

    // Steps 0 and 1 produce findings but do not trip (below maxRepeats=3
    // consecutive repeats once the window fills). The detector records each
    // call; after the third repeat the breaker trips.
    $stoppedAt = null;

    for ($step = 0; $step < 5; $step++) {
        $result = $middleware->handle(guardTestStep(number: $step), $next);

        if ($result->stopped()) {
            $stoppedAt = $step;

            break;
        }
    }

    // The breaker must trip mid-run: by step 3 the window holds 3 repeats.
    expect($stoppedAt)->not->toBeNull()
        ->and($stoppedAt)->toBeLessThanOrEqual(3)
        ->and($runtime->breaker->isOpen($middleware->runId()))->toBeTrue();
});

it('downshifts the step model mid-run when a soft cap is breached', function (): void {
    Event::fake([ModelDownshifted::class]);

    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->budget(['run' => [0.002, 0.01]])
        ->downshiftAt(0.80, toModel: 'gpt-5-mini')
        ->enforce();

    // Step 0: a large message reserves enough USD to cross the $0.002 soft
    // cap (input ~ 1250 tokens * $1.25/1M ≈ $0.0016 + output ≈ $0.001).
    // The downshift fires for this step.
    $capturedModel = null;

    $next = function (PendingStep $step) use (&$capturedModel): StepResult {
        $capturedModel = $step->model;

        return guardTestResult(usage: new SdkTextUsage(inputTokens: 100, outputTokens: 50));
    };

    $middleware->handle(
        guardTestStep(text: str_repeat('expensive prompt content ', 200)),
        $next,
    );

    // The step must have been sent with the downshifted model.
    expect($capturedModel)->toBe('gpt-5-mini');

    Event::assertDispatched(ModelDownshifted::class, function (ModelDownshifted $event): bool {
        return $event->fromModel === 'gpt-5'
            && $event->toModel === 'gpt-5-mini'
            && $event->reason === 'budget-soft-cap';
    });
});

it('halts with a hard-cap exception when the reservation exceeds the cap', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->budget(['run' => [0.0, 0.005]])
        ->enforce();

    $next = fn (PendingStep $step): StepResult => guardTestResult();

    // A message large enough to reserve more than the $0.005 hard cap
    // (~4000 input tokens * $1.25/1M ≈ $0.005).
    $exception = null;

    try {
        $middleware->handle(
            guardTestStep(text: str_repeat('huge prompt that costs too much ', 500)),
            $next,
        );
    } catch (BudgetExceededException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception?->scope)->toBe('run');
});

it('stays inert in observe mode: no downshift, no breaker trip', function (): void {
    Event::fake([ModelDownshifted::class]);

    $runtime = guardTestRuntime(enforce: false);

    $middleware = GuardAgentSteps::make($runtime)
        ->budget(['run' => [1.00, 5.00]])
        ->downshiftAt(0.80, toModel: 'gpt-5-mini')
        ->breakLoops(maxRepeats: 3)
        ->observe();

    $repeated = new SdkToolCall(id: 'tc-1', name: 'search', arguments: ['q' => 'same']);
    $capturedModel = null;

    $next = function (PendingStep $step) use (&$capturedModel, $repeated): StepResult {
        $capturedModel = $step->model;

        return guardTestResult(toolCalls: [$repeated]);
    };

    // Run 5 steps; observe mode must not downshift or trip.
    for ($step = 0; $step < 5; $step++) {
        $result = $middleware->handle(guardTestStep(number: $step), $next);

        expect($result->stopped())->toBeFalse();
    }

    expect($capturedModel)->toBe('gpt-5')
        ->and($runtime->breaker->isOpen($middleware->runId()))->toBeFalse()
        ->and(Event::dispatched(ModelDownshifted::class))->toBeEmpty();
});

it('hard-halts when the kill switch is open for the run', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'tenant-1')
        ->enforce();

    // The middleware generates a unique run id on the first step. Open
    // the kill switch for that run scope after the first call so we know
    // the run id.
    $result = $middleware->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($result->stopped())->toBeFalse();

    $runId = $middleware->runId();
    expect($runId)->not->toBeNull();

    $runtime->killSwitch->kill('run', $runId);

    $result = $middleware->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($result->stopped())->toBeTrue();
});

it('hard-halts when the kill switch is open for the tenant', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'acme')
        ->enforce();

    // Kill tenant 'acme' via the AgentGuard API (fresh runtime key).
    $runtime->killSwitch->kill('tenant', 'acme');

    $result = $middleware->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($result->stopped())->toBeTrue();
});

it('does not halt a different tenant when the kill switch is open for one tenant', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middlewareA = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'acme')
        ->enforce();

    $middlewareB = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'other')
        ->enforce();

    $runtime->killSwitch->kill('tenant', 'acme');

    $resultA = $middlewareA->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());
    $resultB = $middlewareB->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($resultA->stopped())->toBeTrue()
        ->and($resultB->stopped())->toBeFalse();
});

it('hard-halts all tenants when the global kill switch is open', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middlewareA = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'acme')
        ->enforce();

    $middlewareB = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'other')
        ->enforce();

    $runtime->killSwitch->kill('global', \Imgul\AgentGuard\Loop\KillSwitch::GLOBAL_KEY);

    $resultA = $middlewareA->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());
    $resultB = $middlewareB->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($resultA->stopped())->toBeTrue()
        ->and($resultB->stopped())->toBeTrue();
});

it('accepts integer tenant ids from forTenant()', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 42)
        ->budget(['tenant' => [1.00, 5.00]])
        ->enforce();

    // Integer tenant should be cast to string "42" and not rejected.
    $result = $middleware->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($result->stopped())->toBeFalse();
});

it('accepts Stringable tenant ids from forTenant()', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable-tenant';
            }
        })
        ->budget(['tenant' => [1.00, 5.00]])
        ->enforce();

    $result = $middleware->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($result->stopped())->toBeFalse();
});

it('generates a unique run id per middleware instance', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware1 = GuardAgentSteps::make($runtime)->forTenant(fn () => 'acme')->enforce();
    $middleware2 = GuardAgentSteps::make($runtime)->forTenant(fn () => 'acme')->enforce();

    $middleware1->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());
    $middleware2->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($middleware1->runId())->not->toBeNull()
        ->and($middleware2->runId())->not->toBeNull()
        ->and($middleware1->runId())->not->toBe($middleware2->runId());
});

it('resets the run id when reset() is called', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)->forTenant(fn () => 'acme')->enforce();

    $middleware->handle(guardTestStep(), fn (PendingStep $step): StepResult => guardTestResult());

    expect($middleware->runId())->not->toBeNull();

    $middleware->reset();

    expect($middleware->runId())->toBeNull();
});

it('does not trip the breaker for one run when another run loops', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $runA = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'acme')
        ->breakLoops(maxRepeats: 3)
        ->enforce();

    $runB = GuardAgentSteps::make($runtime)
        ->forTenant(fn () => 'acme')
        ->breakLoops(maxRepeats: 3)
        ->enforce();

    $repeatedToolCall = new SdkToolCall(id: 'tc-1', name: 'search', arguments: ['q' => 'same']);

    $nextLoop = fn (PendingStep $step): StepResult => guardTestResult(toolCalls: [$repeatedToolCall]);
    $nextClean = fn (PendingStep $step): StepResult => guardTestResult();

    // Run A loops and trips its breaker.
    for ($step = 0; $step < 5; $step++) {
        $result = $runA->handle(guardTestStep(number: $step), $nextLoop);

        if ($result->stopped()) {
            break;
        }
    }

    expect($runA->breaker->isOpen($runA->runId()))->toBeTrue();

    // Run B should not be affected — its breaker is keyed by its own run id.
    $resultB = $runB->handle(guardTestStep(), $nextClean);

    expect($resultB->stopped())->toBeFalse()
        ->and($runB->breaker->isOpen($runB->runId()))->toBeFalse();
});

it('does not trip the breaker for 5 distinct tool calls below the frequency threshold', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->breakLoops(maxRepeats: 3, frequency: 8)
        ->enforce();

    // 5 calls to 'search' with different queries — below the default
    // frequency threshold of 8.
    $calls = [
        new SdkToolCall(id: 'tc-1', name: 'search', arguments: ['q' => 'alpha']),
        new SdkToolCall(id: 'tc-2', name: 'search', arguments: ['q' => 'beta']),
        new SdkToolCall(id: 'tc-3', name: 'search', arguments: ['q' => 'gamma']),
        new SdkToolCall(id: 'tc-4', name: 'search', arguments: ['q' => 'delta']),
        new SdkToolCall(id: 'tc-5', name: 'search', arguments: ['q' => 'epsilon']),
    ];

    $next = function (PendingStep $step) use (&$callIndex): StepResult {
        $toolCall = $calls[$callIndex] ?? $calls[0];
        $callIndex++;

        return guardTestResult(toolCalls: [$toolCall]);
    };

    $callIndex = 0;

    for ($step = 0; $step < 5; $step++) {
        $result = $middleware->handle(guardTestStep(number: $step), $next);

        expect($result->stopped())->toBeFalse();
    }

    expect($middleware->breaker->isOpen($middleware->runId()))->toBeFalse();
});

it('trips the breaker when frequency threshold is exceeded', function (): void {
    $runtime = guardTestRuntime(enforce: true);

    $middleware = GuardAgentSteps::make($runtime)
        ->breakLoops(maxRepeats: 3, frequency: 8)
        ->enforce();

    // 8 calls to 'search' with different queries — meets the frequency
    // threshold.
    $next = fn (PendingStep $step): StepResult => guardTestResult(
        toolCalls: [new SdkToolCall(id: 'tc-'.uniqid(), name: 'search', arguments: ['q' => uniqid()])],
    );

    $stoppedAt = null;

    for ($step = 0; $step < 10; $step++) {
        $result = $middleware->handle(guardTestStep(number: $step), $next);

        if ($result->stopped()) {
            $stoppedAt = $step;

            break;
        }
    }

    expect($stoppedAt)->not->toBeNull()
        ->and($stoppedAt)->toBeLessThanOrEqual(8);
});
