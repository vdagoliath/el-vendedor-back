<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('has_recipe')->default(false)->after('stock_by_warehouse');
            $table->json('recipe_items')->nullable()->after('has_recipe');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'has_recipe',
                'recipe_items',
            ]);
        });
    }
};
