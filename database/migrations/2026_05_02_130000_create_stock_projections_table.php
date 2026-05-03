<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('product_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->decimal('qty', 16, 4)->default(0);
            // Identifica el último evento que mutó esta proyección. Útil para
            // diagnosticar drift y para que un eventual rebuild sepa en qué
            // punto estaba la proyección antes del último apply.
            $table->string('last_applied_event_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'product_external_id', 'warehouse_external_id']);
            $table->index(['business_id', 'product_external_id']);
            $table->index(['business_id', 'warehouse_external_id']);
        });

        // Backfill inicial desde Product.stock_by_warehouse. Esto deja la
        // proyección alineada con la última fotografía que hicimos del
        // negocio. De aquí en adelante sólo los eventos discretos
        // (sales, purchases, stock_movements, stock_adjustments) la mueven.
        $this->backfillFromExistingProducts();
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_projections');
    }

    private function backfillFromExistingProducts(): void
    {
        Product::query()
            ->withTrashed()
            ->whereNotNull('stock_by_warehouse')
            ->chunk(200, function ($products): void {
                $rows = [];
                $now = now();

                foreach ($products as $product) {
                    $entries = is_array($product->stock_by_warehouse) ? $product->stock_by_warehouse : [];
                    foreach ($entries as $entry) {
                        $warehouseId = $entry['warehouseId'] ?? null;
                        if (! is_string($warehouseId) || trim($warehouseId) === '') {
                            continue;
                        }
                        $qty = isset($entry['quantity']) && is_numeric($entry['quantity'])
                            ? (float) $entry['quantity']
                            : 0;

                        $rows[] = [
                            'business_id' => $product->business_id,
                            'product_external_id' => $product->external_id,
                            'warehouse_external_id' => trim($warehouseId),
                            'qty' => $qty,
                            'last_applied_event_id' => 'backfill:'.$product->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('stock_projections')->insert($rows);
                }
            });
    }
};
