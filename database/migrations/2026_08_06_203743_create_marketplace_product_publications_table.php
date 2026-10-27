<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_product_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('product_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->string('status', 32)->default('draft');
            $table->string('public_title');
            $table->text('public_description')->nullable();
            $table->decimal('public_price', 14, 4);
            $table->string('currency', 3)->default('USD');
            $table->json('images')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'product_external_id'], 'marketplace_publications_product_unique');
            $table->index(['status', 'public_price']);
            $table->index(['business_id', 'status']);
            // El índice de (business_id, warehouse_external_id) lo crea
            // 2026_08_07_151000_add_warehouse_external_id_to_marketplace_product_publications.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_product_publications');
    }
};
