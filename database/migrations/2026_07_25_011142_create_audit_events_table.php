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
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            // Restrictive, not cascading: an audit trail must outlive the
            // account that produced it. Matches user_consents' retention
            // stance (Task 6) until a real retention policy is decided.
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 60);
            // A short stable string ("user"), not a class-qualified name -
            // avoids coupling an append-only record to a namespace that
            // may later change.
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            // Allowlisted fields only - callers must never pass a raw
            // model or unfiltered request payload into these columns.
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason');
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('correlation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
