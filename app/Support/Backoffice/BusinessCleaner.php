<?php

namespace App\Support\Backoffice;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

class BusinessCleaner
{
    /**
     * @return array<string, int>
     */
    public function clear(Business $business): array
    {
        return DB::transaction(function () use ($business): array {
            /** @var Business $lockedBusiness */
            $lockedBusiness = Business::query()
                ->whereKey($business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $businessId = (int) $lockedBusiness->getKey();
            $deleted = [];

            $deleted['sale_lines'] = DB::table('sale_lines')
                ->whereIn('sale_id', DB::table('sales')->select('id')->where('business_id', $businessId))
                ->delete();
            $deleted['purchase_lines'] = DB::table('purchase_lines')
                ->whereIn('purchase_id', DB::table('purchases')->select('id')->where('business_id', $businessId))
                ->delete();

            foreach ($this->tablesToClear() as $table) {
                $deleted[$table] = DB::table($table)
                    ->where('business_id', $businessId)
                    ->delete();
            }

            $lockedBusiness->forceFill([
                'data_reset_version' => ((int) $lockedBusiness->data_reset_version) + 1,
                'data_reset_at' => now(),
            ])->save();

            return $deleted;
        });
    }

    /**
     * @return list<string>
     */
    private function tablesToClear(): array
    {
        return [
            'sync_checkpoints',
            'sync_conflicts',
            'sync_received_events',
            'sync_diagnostics',
            'metrics_snapshots',
            'stock_projections',
            'stock_movements',
            'stock_adjustments',
            'product_losses',
            'product_breakdowns',
            'product_batches',
            'cash_register_sessions',
            'sales',
            'purchases',
            'expenses',
            'products',
            'contacts',
            'categories',
            'units_of_measure',
            'points_of_sale',
            'warehouses',
        ];
    }
}
