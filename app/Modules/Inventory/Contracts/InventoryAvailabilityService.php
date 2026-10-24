<?php

namespace App\Modules\Inventory\Contracts;

interface InventoryAvailabilityService
{
    public function availableFor(
        int $businessId,
        string $productExternalId,
        ?string $warehouseExternalId = null
    ): float;

    /**
     * @param  array<int|string, array{business_id:int, product_external_id:string, warehouse_external_id?:string|null}>  $items
     * @return array<string, float>
     */
    public function availableMany(array $items): array;

    public function assertAvailable(
        int $businessId,
        string $productExternalId,
        float $quantity,
        ?string $warehouseExternalId = null
    ): void;
}
