<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_cloud_backup_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('provider', 40);
            $table->string('provider_account_identifier')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->json('token_payload')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('provider_folder_id')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 40)->nullable();
            $table->text('last_sync_error')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'provider'], 'ocbc_org_provider_uniq');
            $table->foreign('organization_id', 'ocbc_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('organization_backup_cloud_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_backup_id');
            $table->foreignId('organization_id');
            $table->foreignId('connection_id');
            $table->string('provider', 40);
            $table->string('provider_file_id')->nullable();
            $table->string('status', 40);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['organization_backup_id', 'provider'], 'obcc_backup_provider_uniq');
            $table->index(['organization_id', 'provider', 'status'], 'obcc_org_provider_status_idx');
            $table->foreign('organization_backup_id', 'obcc_backup_fk')->references('id')->on('organization_backups')->cascadeOnDelete();
            $table->foreign('organization_id', 'obcc_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('connection_id', 'obcc_connection_fk')->references('id')->on('organization_cloud_backup_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_backup_cloud_copies');
        Schema::dropIfExists('organization_cloud_backup_connections');
    }
};
