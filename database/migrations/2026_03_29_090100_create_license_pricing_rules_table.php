<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 8)->default('CUP');
            $table->decimal('monthly_price', 12, 2);
            $table->unsignedInteger('min_active_pos')->default(0);
            $table->unsignedInteger('max_active_pos')->nullable();
            $table->unsignedInteger('min_active_owners')->nullable();
            $table->unsignedInteger('max_active_owners')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('license_pricing_rules')->insert([
            [
                'name' => 'Hasta 1 punto de venta activo',
                'description' => 'Aplica cuando el negocio tiene 1 punto de venta activo.',
                'currency' => 'CUP',
                'monthly_price' => 650,
                'min_active_pos' => 0,
                'max_active_pos' => 1,
                'min_active_owners' => null,
                'max_active_owners' => null,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Hasta 2 puntos de venta activos',
                'description' => 'Aplica cuando el negocio tiene hasta 2 puntos de venta activos.',
                'currency' => 'CUP',
                'monthly_price' => 3000,
                'min_active_pos' => 2,
                'max_active_pos' => 2,
                'min_active_owners' => null,
                'max_active_owners' => null,
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Hasta 3 puntos de venta activos',
                'description' => 'Aplica cuando el negocio tiene hasta 3 puntos de venta activos.',
                'currency' => 'CUP',
                'monthly_price' => 6000,
                'min_active_pos' => 3,
                'max_active_pos' => 3,
                'min_active_owners' => null,
                'max_active_owners' => null,
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '4 o más puntos de venta activos',
                'description' => 'Aplica cuando el negocio tiene 4 o más puntos de venta activos.',
                'currency' => 'CUP',
                'monthly_price' => 15000,
                'min_active_pos' => 4,
                'max_active_pos' => null,
                'min_active_owners' => null,
                'max_active_owners' => null,
                'sort_order' => 40,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('license_pricing_rules');
    }
};
