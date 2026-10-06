<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->string('title', 160);
            $table->string('message', 600);
            $table->string('action_url', 1024)->nullable();
            $table->json('metadata')->nullable();
            $table->string('source_type', 80);
            $table->string('source_id', 120);
            $table->string('event_type', 80);
            $table->string('dedupe_key', 191);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key'], 'user_notification_dedupe_unique');
            $table->index(['user_id', 'organization_id', 'read_at', 'created_at'], 'user_notification_feed_idx');
            $table->index(['organization_id', 'category', 'created_at'], 'user_notification_org_category_idx');
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('sound_enabled')->default(false);
            $table->decimal('sound_volume', 3, 2)->default(0.50);
            $table->json('disabled_categories')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('user_notifications');
    }
};
