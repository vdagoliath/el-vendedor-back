<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('can_breakdown')->default(false)->after('stock_by_warehouse');
            $table->string('breakdown_target_product_external_id', 191)->nullable()->after('can_breakdown');
            $table->decimal('breakdown_target_quantity', 16, 4)->nullable()->after('breakdown_target_product_external_id');
            $table->string('breakdown_target_title_snapshot', 255)->nullable()->after('breakdown_target_quantity');
            $table->string('breakdown_target_unit_symbol_snapshot', 32)->nullable()->after('breakdown_target_title_snapshot');
        });

        Schema::create('product_breakdowns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_version')->nullable()->after('id');
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('source_product_external_id', 191);
            $table->string('target_product_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->decimal('source_quantity', 16, 4);
            $table->decimal('target_quantity', 16, 4);
            $table->decimal('conversion_ratio', 16, 4);
            $table->string('source_title_snapshot', 255)->nullable();
            $table->string('target_title_snapshot', 255)->nullable();
            $table->string('source_unit_symbol_snapshot', 32)->nullable();
            $table->string('target_unit_symbol_snapshot', 32)->nullable();
            $table->decimal('source_unit_cost', 16, 4)->nullable();
            $table->decimal('target_unit_cost', 16, 4)->nullable();
            $table->decimal('previous_source_quantity', 16, 4)->nullable();
            $table->decimal('previous_target_quantity', 16, 4)->nullable();
            $table->timestamp('breakdown_at')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
            $table->index(['business_id', 'source_product_external_id']);
            $table->index(['business_id', 'target_product_external_id']);
            $table->index('server_version', 'product_breakdowns_server_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_breakdowns');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'can_breakdown',
                'breakdown_target_product_external_id',
                'breakdown_target_quantity',
                'breakdown_target_title_snapshot',
                'breakdown_target_unit_symbol_snapshot',
            ]);
        });
    }
};
