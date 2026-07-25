<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portable string, not a database-native enum: a raw CHECK constraint
     * would need separately-verified syntax across SQLite (tests),
     * MariaDB (current dev), and MySQL 8 (target) - not attempted here.
     * Validity is enforced by the PHP backed enum cast on the model and
     * by AccountStatusTransitioner being the only write path.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 20)->default('active')->after('password');
            $table->index('account_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropColumn('account_status');
        });
    }
};
