<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_backup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('frequency', 20)->default('daily');
            $table->time('time_of_day')->default('03:00:00');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('retention_count')->default(30);
            $table->boolean('notify_on_failure')->default(false);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_failure_message')->nullable();
            $table->timestamps();

            $table->unique('organization_id');
        });

        Schema::create('organization_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('type', 20);
            $table->string('status', 40);
            $table->timestamp('scheduled_for')->nullable();
            $table->string('scheduled_slot', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->string('storage_disk', 80)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->unsignedSmallInteger('format_version')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('notified_failure_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'type', 'status']);
            $table->index(['organization_id', 'scheduled_for']);
            $table->unique(['organization_id', 'type', 'scheduled_slot'], 'org_backup_scheduled_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_backups');
        Schema::dropIfExists('organization_backup_settings');
    }
};
