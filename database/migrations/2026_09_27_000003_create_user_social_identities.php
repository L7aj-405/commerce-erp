<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::create('user_social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('provider', 40);
            $table->string('provider_user_id', 255);
            $table->string('provider_email')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('provider_avatar_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id'], 'usi_provider_uid_uniq');
            $table->index(['user_id', 'provider'], 'usi_user_provider_idx');
            $table->foreign('user_id', 'usi_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_identities');

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
