<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('prices_by_currency')->nullable()->after('purchase_price');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('total_base', 16, 2)->nullable()->after('total');
            $table->decimal('exchange_rate_from_base', 16, 6)->nullable()->after('currency');
        });

        Schema::table('sale_lines', function (Blueprint $table): void {
            $table->decimal('price_base', 16, 4)->nullable()->after('price');
            $table->decimal('sub_total_base', 16, 4)->nullable()->after('sub_total');
            $table->string('currency', 8)->nullable()->after('sub_total_base');
            $table->decimal('exchange_rate_from_base', 16, 6)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'price_base',
                'sub_total_base',
                'currency',
                'exchange_rate_from_base',
            ]);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn([
                'total_base',
                'exchange_rate_from_base',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('prices_by_currency');
        });
    }
};
