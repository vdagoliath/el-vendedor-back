<?php

namespace App\Modules\Inventory\Contracts;

use App\Models\InventoryReservation;
use DateTimeInterface;

interface InventoryReservationService
{
    /**
     * @param  array<int, array{product_external_id:string, quantity:float|int|string, warehouse_external_id?:string|null}>  $lines
     */
    public function reserve(
        int $businessId,
        string $ownerType,
        string|int $ownerId,
        array $lines,
        DateTimeInterface|string|null $expiresAt = null
    ): InventoryReservation;

    public function confirm(InventoryReservation|int $reservation): InventoryReservation;

    public function release(InventoryReservation|int $reservation): InventoryReservation;

    public function cancel(InventoryReservation|int $reservation): InventoryReservation;

    public function expire(InventoryReservation|int $reservation): InventoryReservation;

    public function expirePastDue(?int $limit = null): int;
}
