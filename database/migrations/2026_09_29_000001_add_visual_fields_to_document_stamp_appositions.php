<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the remaining Studio-controlled stamp presentation values at the
 * moment of apposition. Existing appositions retain their exact prior visual
 * behavior through backward-compatible defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_stamp_appositions', function (Blueprint $table) {
            $table->boolean('visible')->default(true)->after('rotation_deg');
            $table->decimal('display_height_mm', 6, 2)->nullable()->after('visible');
            $table->unsignedTinyInteger('opacity')->default(100)->after('display_height_mm');
            $table->boolean('preserve_aspect_ratio')->default(true)->after('opacity');
        });
    }

    public function down(): void
    {
        Schema::table('document_stamp_appositions', function (Blueprint $table) {
            $table->dropColumn(['visible', 'display_height_mm', 'opacity', 'preserve_aspect_ratio']);
        });
    }
};
