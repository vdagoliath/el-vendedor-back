<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Marketplace\V1\ConfirmMarketplaceQuoteRequest;
use App\Http\Requests\Api\Marketplace\V1\ReserveMarketplaceQuoteRequest;
use App\Http\Requests\Api\Marketplace\V1\StoreMarketplaceQuoteRequest;
use App\Http\Resources\Api\Marketplace\V1\MarketplaceQuoteResource;
use App\Http\Resources\Api\Marketplace\V1\MasterOrderResource;
use App\Models\MarketplaceQuote;
use App\Modules\Marketplace\Contracts\MarketplaceEngineInterface;
use App\Modules\Marketplace\DTOs\MarketplaceConfirmationRequest;
use App\Modules\Marketplace\DTOs\MarketplaceQuoteRequest;
use App\Modules\Marketplace\DTOs\MarketplaceReservationRequest;

class MarketplaceQuoteController extends Controller
{
    public function store(
        StoreMarketplaceQuoteRequest $request,
        MarketplaceEngineInterface $engine
    ): MarketplaceQuoteResource {
        $result = $engine->quote(new MarketplaceQuoteRequest(
            lines: $request->quoteLines(),
            consumerId: $request->filled('consumer_id') ? $request->integer('consumer_id') : null,
        ));

        return MarketplaceQuoteResource::make($result->quote)->additional([
            'meta' => ['created' => true],
        ]);
    }

    public function reserve(
        ReserveMarketplaceQuoteRequest $request,
        MarketplaceQuote $quote,
        MarketplaceEngineInterface $engine
    ): MarketplaceQuoteResource {
        $result = $engine->reserve(new MarketplaceReservationRequest($quote));

        return MarketplaceQuoteResource::make($result->quote);
    }

    public function confirm(
        ConfirmMarketplaceQuoteRequest $request,
        MarketplaceQuote $quote,
        MarketplaceEngineInterface $engine
    ): MasterOrderResource {
        $result = $engine->confirm(new MarketplaceConfirmationRequest(
            quote: $quote,
            recipientSnapshot: $request->recipientSnapshot(),
            deliveryAddressSnapshot: $request->deliveryAddressSnapshot(),
            paymentStatus: $request->paymentStatus(),
            deliveryStatus: $request->deliveryStatus(),
            paymentSnapshot: $request->paymentSnapshot(),
            deliverySnapshot: $request->deliverySnapshot(),
        ));

        return MasterOrderResource::make($result->masterOrder);
    }
}
