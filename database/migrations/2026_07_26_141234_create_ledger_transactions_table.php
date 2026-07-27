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
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Normalization and generation belong to Task 2.4's posting
            // engine - this column only defines the immutable storage
            // field and its uniqueness guarantee.
            $table->string('business_reference', 191);
            $table->string('type', 40);
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('currency_scale');
            $table->string('description', 255);
            $table->foreignId('actor_id')->nullable();
            $table->string('related_entity_type', 40)->nullable();
            $table->string('related_entity_id', 36)->nullable();
            $table->string('correlation_id', 36);
            // Schema capability only - Task 2.5 owns when/how this gets
            // populated.
            $table->ulid('reverses_transaction_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('business_reference');
            $table->unique('reverses_transaction_id');
            $table->index(['related_entity_type', 'related_entity_id']);
            $table->index('correlation_id');

            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reverses_transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
