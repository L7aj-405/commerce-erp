<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('active_organization_id')
                ->nullable()
                ->after('remember_token')
                ->constrained('organizations')
                ->nullOnDelete();
            $table->foreignId('active_store_id')
                ->nullable()
                ->after('active_organization_id')
                ->constrained('stores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_store_id');
            $table->dropConstrainedForeignId('active_organization_id');
        });
    }
};
