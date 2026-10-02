<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Productos del catálogo online: precio exacto definido por el dueño,
 * independiente del precio de la publicación en el marketplace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Producto de ElVendedor del que sale este ítem (misma base de productos).
            $table->string('product_external_id', 191)->nullable();
            $table->string('slug', 191);
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 14, 4);
            $table->string('currency', 3);
            $table->string('unit', 32)->nullable();
            $table->string('badge', 48)->nullable();
            $table->string('category_name', 64)->nullable();
            $table->string('category_emoji', 16)->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->unique(['business_id', 'product_external_id']);
            $table->index(['business_id', 'is_visible', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
