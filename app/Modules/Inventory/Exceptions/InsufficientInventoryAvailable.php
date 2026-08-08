<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

class InsufficientInventoryAvailable extends RuntimeException
{
    public function __construct(
        public readonly int $businessId,
        public readonly string $productExternalId,
        public readonly float $requestedQuantity,
        public readonly float $availableQuantity,
        public readonly ?string $warehouseExternalId = null,
    ) {
        parent::__construct(sprintf(
            'Insufficient inventory available for product [%s]. Requested %.4f, available %.4f.',
            $productExternalId,
            $requestedQuantity,
            $availableQuantity,
        ));
    }
}
