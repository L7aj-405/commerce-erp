<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'role_id'])
                ->references(['organization_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->unique(['role_id', 'permission_id']);
            $table->foreign(['organization_id', 'role_id'])
                ->references(['organization_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
        });

        Schema::create('store_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->foreign(['organization_id', 'store_id'])
                ->references(['organization_id', 'id'])
                ->on('stores')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->cascadeOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event')->index();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('store_memberships');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('permissions');
    }
};
