<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets table for the no-Redis fallback driver (DatabaseLedger).
 *
 * The Redis Lua ledger needs no table; this migration only supports the
 * documented weaker-concurrency fallback path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_guard_budgets', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->decimal('spent', 18, 6)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_guard_budgets');
    }
};
