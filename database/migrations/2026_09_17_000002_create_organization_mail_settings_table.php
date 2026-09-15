<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization-scoped outbound email (SMTP) configuration, used to send
 * Invoice/Devis/Bon de livraison documents through the organization's own
 * mailbox instead of the framework's global MAIL_* configuration.
 *
 * One row per organization for V1 — `organization_id` is unique. The SMTP
 * password is stored via the model's `encrypted` cast (see
 * `woocommerce_integrations.consumer_secret` for the same convention) and is
 * never returned to the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('sender_name');
            $table->string('sender_email');
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_username');
            $table->text('smtp_password'); // encrypted at the model layer
            $table->string('smtp_encryption', 8)->default('tls'); // tls | ssl | none
            $table->string('reply_to_email')->nullable();
            $table->string('reply_to_name')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->dateTime('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_mail_settings');
    }
};
