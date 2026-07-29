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
        Schema::table('audit_events', function (Blueprint $table) {
            // Generic string identifier (ULID, UUID, or a future longer
            // provider/domain identifier) - an additive alternative to the
            // existing integer entity_id, never a replacement. Nullable:
            // every existing row stays entity_key = null, unaffected.
            // AuditEvent::record() enforces at most one of entity_id/
            // entity_key being non-null at the application layer.
            $table->string('entity_key', 191)->nullable()->after('entity_id');

            // Lookup index for entity_key-based audit queries, mirroring
            // the existing (entity_type, entity_id) index.
            $table->index(['entity_type', 'entity_key'], 'audit_events_entity_type_entity_key_index');

            // Exact-once uniqueness for a given (entity_type, entity_key,
            // action) triple - stops a second administrative-adjustment
            // audit event from ever being inserted for the same ledger
            // transaction. Relies on the same already-verified MySQL/
            // MariaDB behavior that every existing row's NULL entity_key
            // never collides with another NULL, so this has zero effect
            // on any row this migration does not itself populate.
            $table->unique(['entity_type', 'entity_key', 'action'], 'audit_events_entity_key_action_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropUnique('audit_events_entity_key_action_unique');
            $table->dropIndex('audit_events_entity_type_entity_key_index');
            $table->dropColumn('entity_key');
        });
    }
};
