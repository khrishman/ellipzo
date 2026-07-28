<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reversal_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('original_ledger_transaction_id');
            $table->ulid('reversal_transaction_id')->nullable();
            $table->string('idempotency_key', 191);
            $table->char('fingerprint', 64);
            $table->string('status', 20)->default('pending');
            $table->foreignId('actor_id')->nullable();
            $table->string('reason', 255);
            $table->string('correlation_id', 36);
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('review_required_at')->nullable();
            $table->timestamps();

            $table->unique('original_ledger_transaction_id');
            $table->unique('reversal_transaction_id');
            $table->unique('idempotency_key');
            $table->index('status');

            $table->foreign('original_ledger_transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
            $table->foreign('reversal_transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reversal_requests');
    }
};
