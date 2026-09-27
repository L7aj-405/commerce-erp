<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_cloud_backup_connections', function (Blueprint $table) {
            $table->longText('token_payload')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('organization_cloud_backup_connections', function (Blueprint $table) {
            $table->json('token_payload')->nullable()->change();
        });
    }
};
