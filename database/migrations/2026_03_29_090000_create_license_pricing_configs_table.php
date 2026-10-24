<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_pricing_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('active_pos_operation_window_days')->default(30);
            $table->timestamps();
        });

        DB::table('license_pricing_configs')->insert([
            'active_pos_operation_window_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('license_pricing_configs');
    }
};
