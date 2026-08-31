<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_imports', function (Blueprint $table) {
            $table->string('global_error_code', 64)->nullable()->after('status');
            $table->text('global_error_message')->nullable()->after('global_error_code');
        });
        Schema::table('product_import_rows', function (Blueprint $table) {
            $table->string('error_code', 64)->nullable()->after('status');
            $table->string('error_phase', 16)->nullable()->after('error_code');
            $table->text('error_message')->nullable()->after('error_phase');
        });
    }

    public function down(): void
    {
        Schema::table('product_import_rows', function (Blueprint $table) {
            $table->dropColumn(['error_code', 'error_phase', 'error_message']);
        });
        Schema::table('product_imports', function (Blueprint $table) {
            $table->dropColumn(['global_error_code', 'global_error_message']);
        });
    }
};
