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
        Schema::create('country_capabilities', function (Blueprint $table) {
            $table->char('country_code', 2)->primary();
            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();

            // Every flag defaults false: deny-by-default, uniformly, for
            // every country including this one. Nothing here is ever
            // seeded true for a specific country - enabling a capability
            // is a separate, explicit, later operator action.
            $table->boolean('registration_enabled')->default(false);
            $table->boolean('earning_enabled')->default(false);
            $table->boolean('advertising_enabled')->default(false);

            // Inert in this task: no deposit/withdrawal feature reads
            // these yet. They exist now so this schema doesn't need
            // revisiting when Payments/Wallet/Bybit Pay are built.
            $table->boolean('deposits_enabled')->default(false);
            $table->boolean('withdrawals_enabled')->default(false);

            $table->unsignedTinyInteger('minimum_age')->default(18);

            // Free-text operator context (e.g. "pending payment provider
            // coverage"), never a legal conclusion Claude writes on a
            // country's behalf.
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('country_capabilities');
    }
};
