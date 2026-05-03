<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            // Dispositivo que actualmente tiene el lease de "master" para
            // arbitrar inventario en esta sesión. Cualquier follower puede
            // reclamar el lease cuando expire.
            $table->string('master_device_id', 64)->nullable()->after('warehouse_external_id');
            $table->timestamp('master_lease_expires_at')->nullable()->after('master_device_id');
            $table->index(['business_id', 'master_lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'master_lease_expires_at']);
            $table->dropColumn(['master_device_id', 'master_lease_expires_at']);
        });
    }
};
