<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addendum: who physically transported the goods (chauffeur / livreur) and the
 * optional vehicle trace used on the printed Bon de sortie.
 *
 * `driver_name` is an immutable SNAPSHOT captured at assignment/shipping time so
 * an old Bon de sortie keeps showing the right person even if the linked user
 * profile is later renamed or the user is removed. `driver_user_id` is only a
 * soft link (nullOnDelete) — the driver need not be an ERP user at all.
 *
 * Nothing here has any inventory or finance effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->foreignId('driver_user_id')->nullable()->after('shipped_at')->constrained('users')->nullOnDelete();
            $table->string('driver_name')->nullable()->after('driver_user_id');
            $table->string('driver_phone', 64)->nullable()->after('driver_name');
            $table->string('vehicle')->nullable()->after('driver_phone');
            $table->string('vehicle_registration', 64)->nullable()->after('vehicle');
            $table->text('shipping_note')->nullable()->after('vehicle_registration');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_user_id');
            $table->dropColumn(['driver_name', 'driver_phone', 'vehicle', 'vehicle_registration', 'shipping_note']);
        });
    }
};
