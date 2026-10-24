<?php

namespace App\Modules\Marketplace\Contracts;

use App\Modules\Marketplace\DTOs\MarketplaceConfirmationRequest;
use App\Modules\Marketplace\DTOs\MarketplaceConfirmationResult;
use App\Modules\Marketplace\DTOs\MarketplaceQuoteRequest;
use App\Modules\Marketplace\DTOs\MarketplaceQuoteResult;
use App\Modules\Marketplace\DTOs\MarketplaceReservationRequest;
use App\Modules\Marketplace\DTOs\MarketplaceReservationResult;

interface MarketplaceEngineInterface
{
    public function quote(MarketplaceQuoteRequest $request): MarketplaceQuoteResult;

    public function reserve(MarketplaceReservationRequest $request): MarketplaceReservationResult;

    public function confirm(MarketplaceConfirmationRequest $request): MarketplaceConfirmationResult;
}
