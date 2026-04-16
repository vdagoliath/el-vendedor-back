<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->after('token')
                ->constrained('businesses')->nullOnDelete();
            $table->string('employee_external_id', 191)->nullable()->after('business_id');
            $table->string('device_uuid', 191)->nullable()->after('employee_external_id');

            $table->index(['business_id', 'employee_external_id']);
            $table->index('device_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'employee_external_id']);
            $table->dropIndex(['device_uuid']);
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn(['employee_external_id', 'device_uuid']);
        });
    }
};
