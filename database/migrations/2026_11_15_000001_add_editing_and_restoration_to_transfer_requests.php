<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer request editing, preparation review and safe restoration.
 *
 *  - `lock_version`     optimistic concurrency token. Every line edit / review
 *                       must quote the version it was based on; a stale edit is
 *                       rejected instead of silently overwriting a colleague.
 *  - `idempotency_key`  client operation id for manual creation so a double
 *                       submit (or an offline retry) never creates two requests.
 *  - `note`             optional free-text reason on a manual request.
 *  - `restored_*`       trail of a "Rétablir la demande" on a request cancelled
 *                       by mistake. The cancellation itself stays in audit_logs
 *                       and in `cancellation_reason` history via the audit.
 *  - line `requested_quantity`  the originally requested quantity, kept when the
 *                       preparer confirms a different (lower) quantity to send.
 *
 * Nothing here has an inventory effect; the ledger still only moves on receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('status');
            $table->string('idempotency_key', 64)->nullable()->after('lock_version');
            $table->text('note')->nullable()->after('idempotency_key');
            $table->foreignId('restored_by_user_id')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable()->after('restored_by_user_id');

            $table->unique(['organization_id', 'idempotency_key'], 'transfer_request_idempotency_unique');
        });

        Schema::table('transfer_request_lines', function (Blueprint $table) {
            $table->decimal('requested_quantity', 19, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_request_lines', function (Blueprint $table) {
            $table->dropColumn('requested_quantity');
        });

        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->dropUnique('transfer_request_idempotency_unique');
            $table->dropConstrainedForeignId('restored_by_user_id');
            $table->dropColumn(['lock_version', 'idempotency_key', 'note', 'restored_at']);
        });
    }
};
