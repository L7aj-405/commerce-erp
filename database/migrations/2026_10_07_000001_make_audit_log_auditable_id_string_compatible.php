<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MORPH_INDEX = 'audit_logs_auditable_type_auditable_id_index';

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Keep the production change in one atomic MySQL DDL operation so
            // the morph index is never absent between separately committed
            // ALTER TABLE statements.
            DB::statement(
                'ALTER TABLE `audit_logs` '
                .'DROP INDEX `'.self::MORPH_INDEX.'`, '
                .'MODIFY `auditable_id` VARCHAR(64) NULL, '
                .'ADD INDEX `'.self::MORPH_INDEX.'` (`auditable_type`, `auditable_id`)'
            );

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(self::MORPH_INDEX);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            // BIGINT identifiers use at most 20 decimal characters; 64 also
            // accommodates UUIDs, ULIDs and future opaque model identifiers.
            // MySQL converts existing numeric values to their decimal string
            // representation without discarding any audit rows.
            $table->string('auditable_id', 64)->nullable()->change();
            $table->index(['auditable_type', 'auditable_id'], self::MORPH_INDEX);
        });
    }

    public function down(): void
    {
        $containsStringIdentifier = DB::table('audit_logs')
            ->select(['id', 'auditable_id'])
            ->whereNotNull('auditable_id')
            ->lazyById(1000)
            ->contains(fn (object $row): bool => ! ctype_digit((string) $row->auditable_id));

        if ($containsStringIdentifier) {
            throw new \RuntimeException(
                'Cannot restore audit_logs.auditable_id to BIGINT while UUID/ULID audit targets exist.'
            );
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `audit_logs` '
                .'DROP INDEX `'.self::MORPH_INDEX.'`, '
                .'MODIFY `auditable_id` BIGINT UNSIGNED NULL, '
                .'ADD INDEX `'.self::MORPH_INDEX.'` (`auditable_type`, `auditable_id`)'
            );

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(self::MORPH_INDEX);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('auditable_id')->nullable()->change();
            $table->index(['auditable_type', 'auditable_id'], self::MORPH_INDEX);
        });
    }
};
