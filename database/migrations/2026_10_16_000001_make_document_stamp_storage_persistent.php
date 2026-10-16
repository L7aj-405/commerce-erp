<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_document_stamps', function (Blueprint $table) {
            // Existing rows were written to storage/app/private through the
            // local disk; new rows explicitly record the persistent disk.
            $table->string('storage_disk', 32)->default('local')->after('image_path');
        });

        Schema::table('document_stamp_appositions', function (Blueprint $table) {
            $table->string('storage_disk', 32)->default('local')->after('image_path');
            // New official appositions freeze the exact image bytes so an
            // issued document no longer depends on a mutable filesystem file.
            $table->longText('image_data_uri')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('document_stamp_appositions', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'image_data_uri']);
        });

        Schema::table('organization_document_stamps', function (Blueprint $table) {
            $table->dropColumn('storage_disk');
        });
    }
};
