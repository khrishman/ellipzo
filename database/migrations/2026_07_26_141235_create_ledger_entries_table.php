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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('ledger_transaction_id');
            $table->ulid('wallet_account_id');
            $table->string('entry_type', 10);
            // Signed, not unsigned: unsigned BIGINT's positive range
            // exceeds PHP's own signed 64-bit int range and would behave
            // inconsistently across engines. Strictly-positive is an
            // application-layer invariant (Task 2.4), never encoded here.
            $table->bigInteger('amount_atomic');
            $table->timestamp('created_at')->useCurrent();

            // Explicit, defined before each FK so MySQL/MariaDB reuse it
            // instead of creating a second implicit index.
            $table->index('ledger_transaction_id');
            $table->foreign('ledger_transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();

            $table->index('wallet_account_id');
            $table->foreign('wallet_account_id')->references('id')->on('wallet_accounts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
