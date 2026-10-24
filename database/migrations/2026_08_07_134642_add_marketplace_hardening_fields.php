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
        Schema::table('marketplace_product_publications', function (Blueprint $table): void {
            $table->index(['status', 'public_title'], 'marketplace_publications_search_index');
            $table->index(['business_id', 'status', 'public_price'], 'marketplace_publications_business_price_index');
        });

        Schema::table('marketplace_quotes', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'marketplace_quotes_status_created_index');
            $table->index(['status', 'updated_at'], 'marketplace_quotes_status_updated_index');
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'inventory_reservations_status_updated_index');
        });

        Schema::table('master_orders', function (Blueprint $table): void {
            $table->string('payment_status', 32)->default('pending')->after('status');
            $table->string('delivery_status', 32)->default('pending')->after('payment_status');
            $table->json('payment_snapshot')->nullable()->after('delivery_address_snapshot');
            $table->json('delivery_snapshot')->nullable()->after('payment_snapshot');

            $table->index(['status', 'payment_status'], 'master_orders_status_payment_index');
            $table->index(['status', 'delivery_status'], 'master_orders_status_delivery_index');
            $table->index(['payment_status', 'created_at'], 'master_orders_payment_created_index');
            $table->index(['delivery_status', 'created_at'], 'master_orders_delivery_created_index');
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'seller_orders_status_updated_index');
            $table->index(['business_id', 'status', 'updated_at'], 'seller_orders_business_status_updated_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropIndex('seller_orders_business_status_updated_index');
            $table->dropIndex('seller_orders_status_updated_index');
        });

        Schema::table('master_orders', function (Blueprint $table): void {
            $table->dropIndex('master_orders_delivery_created_index');
            $table->dropIndex('master_orders_payment_created_index');
            $table->dropIndex('master_orders_status_delivery_index');
            $table->dropIndex('master_orders_status_payment_index');
            $table->dropColumn(['payment_status', 'delivery_status', 'payment_snapshot', 'delivery_snapshot']);
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_status_updated_index');
        });

        Schema::table('marketplace_quotes', function (Blueprint $table): void {
            $table->dropIndex('marketplace_quotes_status_updated_index');
            $table->dropIndex('marketplace_quotes_status_created_index');
        });

        Schema::table('marketplace_product_publications', function (Blueprint $table): void {
            $table->dropIndex('marketplace_publications_business_price_index');
            $table->dropIndex('marketplace_publications_search_index');
        });
    }
};
