<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type', 64);
            $table->string('owner_id', 191);
            $table->string('status', 32)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'expires_at']);
            $table->index(['owner_type', 'owner_id']);
            $table->unique(['owner_type', 'owner_id', 'business_id'], 'inventory_reservations_owner_unique');
        });

        Schema::create('inventory_reservation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('product_external_id', 191);
            $table->string('warehouse_external_id', 191)->nullable();
            $table->decimal('quantity', 16, 4);
            $table->timestamps();

            $table->index(['business_id', 'product_external_id', 'warehouse_external_id'], 'inventory_reservation_lines_lookup_index');
            $table->index(['business_id', 'product_external_id'], 'inventory_reservation_lines_product_index');
            $table->unique([
                'inventory_reservation_id',
                'business_id',
                'product_external_id',
                'warehouse_external_id',
            ], 'inventory_reservation_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservation_lines');
        Schema::dropIfExists('inventory_reservations');
    }
};
