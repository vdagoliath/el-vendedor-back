<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('quote_number', 64)->unique();
            $table->unsignedBigInteger('consumer_id')->nullable();
            $table->string('status', 32)->default('quoted');
            $table->decimal('subtotal', 14, 4)->default(0);
            $table->decimal('delivery_total', 14, 4)->default(0);
            $table->decimal('fees_total', 14, 4)->default(0);
            $table->decimal('grand_total', 14, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamp('expires_at');
            $table->json('payload_snapshot')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('consumer_id');
        });

        Schema::create('marketplace_quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_product_publication_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('product_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->string('title_snapshot');
            $table->decimal('unit_price', 14, 4);
            $table->decimal('quantity', 14, 4);
            $table->decimal('subtotal', 14, 4);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->index(['marketplace_quote_id', 'business_id']);
            $table->index(['business_id', 'product_external_id', 'warehouse_external_id'], 'marketplace_quote_lines_inventory_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_quote_lines');
        Schema::dropIfExists('marketplace_quotes');
    }
};
