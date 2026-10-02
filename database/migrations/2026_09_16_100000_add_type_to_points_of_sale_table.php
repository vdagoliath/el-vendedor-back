<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('points_of_sale', function (Blueprint $table): void {
            // Tipo de punto de venta: 'regular' (venta directa) o 'restaurant'
            // (salon con mesas y comandas).
            $table->string('type', 32)->default('regular')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('points_of_sale', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
