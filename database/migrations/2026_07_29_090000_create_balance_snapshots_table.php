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
        Schema::create('balance_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('wallet_account_id');
            // Signed, not unsigned - mirrors ledger_entries.amount_atomic's
            // own reasoning: unsigned BIGINT's positive range exceeds
            // PHP's own signed 64-bit int range.
            $table->bigInteger('balance_atomic');
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('currency_scale');
            // Jointly nullable: both null together means "zero entries,
            // zero balance"; enforced at the model layer, not here.
            $table->ulid('cutoff_ledger_entry_id')->nullable();
            $table->timestamp('cutoff_entry_created_at')->nullable();
            $table->unsignedBigInteger('entry_count');
            $table->unsignedTinyInteger('fingerprint_version');
            $table->char('fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            // No updated_at - a snapshot is fully immutable once created.

            $table->index(['wallet_account_id', 'created_at'], 'balance_snapshots_account_created_at_index');
            $table->index('cutoff_ledger_entry_id', 'balance_snapshots_cutoff_entry_index');

            // Explicit, defined before each FK so MySQL/MariaDB reuse it
            // instead of creating a second implicit index. Deliberately no
            // unique constraint on wallet_account_id - multiple snapshots
            // per account are allowed by design.
            $table->foreign('wallet_account_id', 'balance_snapshots_wallet_account_id_foreign')
                ->references('id')->on('wallet_accounts')->restrictOnDelete();
            $table->foreign('cutoff_ledger_entry_id', 'balance_snapshots_cutoff_entry_id_foreign')
                ->references('id')->on('ledger_entries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balance_snapshots');
    }
};
