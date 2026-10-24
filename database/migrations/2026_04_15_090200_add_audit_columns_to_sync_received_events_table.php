<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_received_events', function (Blueprint $table): void {
            $table->string('employee_external_id', 191)->nullable()->after('device_id');
            $table->string('token_ability', 32)->nullable()->after('employee_external_id');

            $table->index(['business_id', 'employee_external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_received_events', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'employee_external_id']);
            $table->dropColumn(['employee_external_id', 'token_ability']);
        });
    }
};
