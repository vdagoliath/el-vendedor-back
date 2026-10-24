<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_product_publications', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketplace_product_publications', 'warehouse_external_id')) {
                $table->string('warehouse_external_id', 191)->nullable()->after('product_external_id');
            }
        });

        $this->backfillWarehouseExternalIds();

        Schema::table('marketplace_product_publications', function (Blueprint $table): void {
            $table->index(['business_id', 'warehouse_external_id'], 'marketplace_publications_business_warehouse_index');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_product_publications', function (Blueprint $table): void {
            if (Schema::hasColumn('marketplace_product_publications', 'warehouse_external_id')) {
                $table->dropIndex('marketplace_publications_business_warehouse_index');
                $table->dropColumn('warehouse_external_id');
            }
        });
    }

    private function backfillWarehouseExternalIds(): void
    {
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        DB::table('marketplace_product_publications')
            ->where(function ($query): void {
                $query->whereNull('warehouse_external_id')
                    ->orWhere('warehouse_external_id', '');
            })
            ->orderBy('business_id')
            ->orderBy('id')
            ->get(['id', 'business_id'])
            ->each(function ($publication): void {
                $warehouseId = DB::table('warehouses')
                    ->where('business_id', $publication->business_id)
                    ->orderByRaw("case when external_id = 'central_warehouse' then 0 else 1 end")
                    ->orderBy('id')
                    ->value('external_id');

                DB::table('marketplace_product_publications')
                    ->where('id', $publication->id)
                    ->update([
                        'warehouse_external_id' => $warehouseId ?: 'central_warehouse',
                    ]);
            });
    }
};
