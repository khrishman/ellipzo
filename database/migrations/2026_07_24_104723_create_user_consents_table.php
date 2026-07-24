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
        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();
            // Restrictive, not cascading: whether a user's consent history
            // should be deleted, retained, or anonymized alongside their
            // account is a retention/compliance decision that has not been
            // separately reviewed. Restricting the delete is the safe
            // default until that policy exists - it fails loudly instead
            // of silently discarding a legal record.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('document', 40);
            $table->string('version', 20);
            $table->timestamp('accepted_at');
            $table->string('method', 40);
            $table->timestamps();

            $table->unique(['user_id', 'document', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
