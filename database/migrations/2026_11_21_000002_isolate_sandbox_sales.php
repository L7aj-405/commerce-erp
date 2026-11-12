<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('sandbox_id')->nullable()->constrained('sandboxes')->restrictOnDelete();
                $table->index(['organization_id', 'sandbox_id', 'store_id'], $name.'_environment_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_orders', 'quotations'] as $name) {
            if (DB::table($name)->whereNotNull('sandbox_id')->exists()) {
                throw new RuntimeException('Cannot remove environment isolation while Test sales records exist.');
            }
        }
        foreach (['sales_orders', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_environment_idx');
                $table->dropConstrainedForeignId('sandbox_id');
            });
        }
    }
};
