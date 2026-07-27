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
        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope_type', 20);
            $table->string('scope_key', 100);
            $table->foreignId('user_id')->nullable();
            $table->string('account_type', 40);
            $table->char('currency_code', 3)->default('USD');
            $table->unsignedTinyInteger('currency_scale')->default(6);
            $table->timestamp('created_at')->useCurrent();

            // Explicit, short name - Laravel's auto-generated name for this
            // four-column composite (70 chars) exceeds MySQL/MariaDB's
            // 64-character identifier limit.
            $table->unique(['scope_type', 'scope_key', 'account_type', 'currency_code'], 'wallet_accounts_identity_unique');

            // Explicit, defined before the FK constraint so MySQL/MariaDB
            // reuse it instead of creating a second implicit index -
            // required by the provisioner's user_id-based preflight query
            // and the user() relationship.
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_accounts');
    }
};
