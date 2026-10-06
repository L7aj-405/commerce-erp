<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_health_heartbeats', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        app(PermissionProvisioner::class)->provisionAllOrganizations();
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health_heartbeats');
        // Retain permission catalogue entries and assignments on rollback.
    }
};
