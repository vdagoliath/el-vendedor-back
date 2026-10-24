<?php

namespace App\Modules\Marketplace\DTOs;

use App\Models\MasterOrder;

class MarketplaceConfirmationResult
{
    public function __construct(
        public readonly MasterOrder $masterOrder,
    ) {}
}
