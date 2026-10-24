<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_quote_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('order_number', 64)->unique();
            $table->unsignedBigInteger('consumer_id')->nullable();
            $table->string('status', 32)->default('confirmed');
            $table->json('recipient_snapshot')->nullable();
            $table->json('delivery_address_snapshot')->nullable();
            $table->decimal('subtotal', 14, 4)->default(0);
            $table->decimal('delivery_total', 14, 4)->default(0);
            $table->decimal('fees_total', 14, 4)->default(0);
            $table->decimal('grand_total', 14, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('consumer_id');
        });

        Schema::create('seller_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('master_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('seller_order_number', 64)->unique();
            $table->string('status', 32)->default('reserved');
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('inventory_reservations')->nullOnDelete();
            $table->decimal('subtotal', 14, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['master_order_id', 'business_id']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('seller_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            $table->string('product_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->string('title_snapshot');
            $table->decimal('unit_price', 14, 4);
            $table->decimal('quantity', 14, 4);
            $table->decimal('subtotal', 14, 4);
            $table->timestamps();

            $table->index(['seller_order_id', 'product_external_id']);
        });

        Schema::create('marketplace_order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('master_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 64)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['master_order_id', 'created_at']);
            $table->index(['seller_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_status_histories');
        Schema::dropIfExists('seller_order_lines');
        Schema::dropIfExists('seller_orders');
        Schema::dropIfExists('master_orders');
    }
};
