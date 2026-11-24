<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | "observe" runs every detector and emits all events but enforces nothing.
    | "enforce" activates the action ladder (soft-cap downshift, hard-cap halt,
    | loop braking, kill switch). Ships in observe mode so adoption is
    | zero-breakage by default.
    |
    */

    'mode' => env('AGENT_GUARD_MODE', 'observe'),

    /*
    |--------------------------------------------------------------------------
    | Ledger driver
    |--------------------------------------------------------------------------
    |
    | "redis" enforces budgets with atomic Lua reservations (race-proof under
    | concurrent workers). "database" is the documented fallback with weaker
    | concurrency guarantees (DB row locks instead of Redis atomicity).
    |
    */

    'driver' => env('AGENT_GUARD_LEDGER', 'redis'),

    'kill_switch' => env('AGENT_GUARD_KILL_SWITCH', true),

    'estimate' => [
        'output_tokens' => 256,
    ],

    /*
    |--------------------------------------------------------------------------
    | Loop detection
    |--------------------------------------------------------------------------
    */

    'loop' => [

        // Sliding-window size (in calls) for fingerprint/frequency detection.
        'window_size' => 50,

        // Same normalized fingerprint repeated this many times trips the breaker.
        'max_repeats' => 3,

        // Step-over-step input-token growth ratio with no progress = reasoning loop.
        'max_step_growth_ratio' => 2.5,

        // Local similarity strategy: 'none' | 'simhash' | 'trigram' | adapter class.
        'similarity' => 'simhash',

        'breaker' => [
            'cooldown_seconds' => 60,
            'half_open_probe' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets
    |--------------------------------------------------------------------------
    */

    'budget' => [

        // Default caps per scope (null = unlimited). Keys are versioned with the
        // scope they guard; see BudgetScope for the full list.
        'defaults' => [
            'run_usd' => null,
            'run_tokens' => null,
            'agent_daily_usd' => null,
            'agent_daily_tokens' => null,
            'tenant_daily_usd' => 15.00, // recommended first cap
            'tenant_daily_tokens' => null,
            'tool_usd' => null,
            'tool_tokens' => null,
            'tool_daily_calls' => 100,
        ],

        // Three-stage action ladder: observe -> soft-cap downshift -> hard kill.
        'action_ladder' => [
            'soft' => 'downshift',
            'hard' => 'halt',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Downshift ladder
    |--------------------------------------------------------------------------
    |
    | Ordered tiers applied when a soft cap is breached. Each tier swaps the
    | model via PendingStep::withModel() and tightens tools/steps to mitigate
    | the capability cliff (prevents malformed-output retry spirals).
    |
    */

    'downshift' => [
        'ladders' => [
            'default' => [
                [
                    'threshold' => 0.80,
                    'toModel' => 'claude-haiku-4-5',
                    'withoutTools' => [],
                    'withMaxSteps' => 3,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | Versioned JSON catalog synced from an open pricing source. Prices are
    | data, never hardcoded in release tags. Runtime/DB overrides take
    | precedence over the catalog when allow_overrides is enabled.
    |
    */

    'pricing' => [
        'catalog' => __DIR__.'/../src/Support/Pricing/prices.json',
        'allow_overrides' => true,
    ],

];
