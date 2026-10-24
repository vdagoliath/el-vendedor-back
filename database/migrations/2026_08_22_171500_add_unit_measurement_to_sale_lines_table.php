<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table): void {
            $table->string('unit_of_measure_id', 191)->nullable()->after('sub_total');
            $table->json('unit_of_measurement')->nullable()->after('unit_of_measure_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table): void {
            $table->dropColumn(['unit_of_measure_id', 'unit_of_measurement']);
        });
    }
};
