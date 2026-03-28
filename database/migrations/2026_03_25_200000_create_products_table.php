<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('code', 191);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 50)->default('product');
            $table->decimal('regular_price', 14, 4)->default(0);
            $table->decimal('purchase_price', 14, 4)->default(0);
            $table->string('barcode_type', 50)->nullable();
            $table->decimal('min_stock', 14, 4)->nullable();
            $table->string('category_external_id', 191)->nullable();
            $table->json('unit_of_measurement')->nullable();
            $table->json('unit_of_measurement_purchase')->nullable();
            $table->json('stock_by_warehouse')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'code']);
            $table->index(['business_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
