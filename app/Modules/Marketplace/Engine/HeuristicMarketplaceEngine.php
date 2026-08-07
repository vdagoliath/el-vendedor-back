<?php

namespace App\Modules\Marketplace\Engine;

use App\Events\MarketplaceOrderConfirmed;
use App\Events\MarketplaceQuoteReserved;
use App\Models\InventoryReservation;
use App\Models\MarketplaceOrderStatusHistory;
use App\Models\MarketplaceProductPublication;
use App\Models\MarketplaceQuote;
use App\Models\MarketplaceQuoteLine;
use App\Models\MasterOrder;
use App\Models\SellerOrder;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;
use App\Modules\Inventory\Contracts\InventoryReservationService;
use App\Modules\Inventory\Exceptions\InsufficientInventoryAvailable;
use App\Modules\Marketplace\Contracts\MarketplaceEngineInterface;
use App\Modules\Marketplace\DTOs\MarketplaceConfirmationRequest;
use App\Modules\Marketplace\DTOs\MarketplaceConfirmationResult;
use App\Modules\Marketplace\DTOs\MarketplaceQuoteRequest;
use App\Modules\Marketplace\DTOs\MarketplaceQuoteResult;
use App\Modules\Marketplace\DTOs\MarketplaceReservationRequest;
use App\Modules\Marketplace\DTOs\MarketplaceReservationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HeuristicMarketplaceEngine implements MarketplaceEngineInterface
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function quote(MarketplaceQuoteRequest $request): MarketplaceQuoteResult
    {
        $requestedLines = $this->aggregateRequestedLines($request->lines);

        if ($requestedLines === []) {
            throw ValidationException::withMessages([
                'lines' => 'La cotizacion requiere al menos una linea valida.',
            ]);
        }

        $publications = MarketplaceProductPublication::query()
            ->published()
            ->whereIn('id', array_keys($requestedLines))
            ->get()
            ->keyBy('id');

        if ($publications->count() !== count($requestedLines)) {
            throw ValidationException::withMessages([
                'lines' => 'Una o mas publicaciones no estan disponibles.',
            ]);
        }

        $availability = $this->availability->availableMany(
            $publications
                ->mapWithKeys(fn (MarketplaceProductPublication $publication): array => [
                    'publication:'.$publication->id => [
                        'business_id' => $publication->business_id,
                        'product_external_id' => $publication->product_external_id,
                        'warehouse_external_id' => $publication->warehouse_external_id,
                    ],
                ])
                ->all()
        );

        $currency = null;
        $subtotal = 0.0;
        $lineSnapshots = [];

        foreach ($requestedLines as $publicationId => $quantity) {
            /** @var MarketplaceProductPublication $publication */
            $publication = $publications->get($publicationId);
            $available = (float) ($availability['publication:'.$publication->id] ?? 0.0);

            if ($available < $quantity) {
                throw ValidationException::withMessages([
                    'lines' => "La publicacion {$publication->id} no tiene inventario suficiente.",
                ]);
            }

            $currency ??= $publication->currency;
            if ($currency !== $publication->currency) {
                throw ValidationException::withMessages([
                    'lines' => 'La cotizacion no puede mezclar monedas.',
                ]);
            }

            $unitPrice = (float) $publication->public_price;
            $lineSubtotal = $unitPrice * $quantity;
            $subtotal += $lineSubtotal;

            $lineSnapshots[] = [
                'marketplace_product_publication_id' => $publication->id,
                'business_id' => $publication->business_id,
                'product_external_id' => $publication->product_external_id,
                'warehouse_external_id' => $publication->warehouse_external_id,
                'title_snapshot' => $publication->public_title,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $lineSubtotal,
                'currency' => $publication->currency,
                'available_at_quote' => $available,
            ];
        }

        $quote = DB::transaction(function () use ($request, $subtotal, $currency, $lineSnapshots): MarketplaceQuote {
            $quote = MarketplaceQuote::query()->create([
                'quote_number' => 'MQ-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                'consumer_id' => $request->consumerId,
                'status' => MarketplaceQuote::StatusQuoted,
                'subtotal' => $subtotal,
                'delivery_total' => 0,
                'fees_total' => 0,
                'grand_total' => $subtotal,
                'currency' => $currency ?? 'USD',
                'expires_at' => now()->addMinutes(15),
                'payload_snapshot' => [
                    'lines' => $lineSnapshots,
                ],
            ]);

            foreach ($lineSnapshots as $line) {
                $quote->lines()->create([
                    'marketplace_product_publication_id' => $line['marketplace_product_publication_id'],
                    'business_id' => $line['business_id'],
                    'product_external_id' => $line['product_external_id'],
                    'warehouse_external_id' => $line['warehouse_external_id'],
                    'title_snapshot' => $line['title_snapshot'],
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                    'currency' => $line['currency'],
                ]);
            }

            return $quote->refresh()->load('lines');
        });

        return new MarketplaceQuoteResult($quote);
    }

    public function reserve(MarketplaceReservationRequest $request): MarketplaceReservationResult
    {
        $quote = $request->quote->loadMissing('lines');

        if ($quote->status === MarketplaceQuote::StatusReserved) {
            return new MarketplaceReservationResult(
                $quote,
                $quote->payload_snapshot['reservation_ids'] ?? [],
            );
        }

        if ($quote->status !== MarketplaceQuote::StatusQuoted || $quote->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'quote' => 'La cotizacion no esta disponible para reserva.',
            ]);
        }

        $reservationIds = DB::transaction(function () use ($quote): array {
            $reservationIds = [];

            $quote->lines
                ->groupBy('business_id')
                ->each(function ($lines, int $businessId) use ($quote, &$reservationIds): void {
                    try {
                        $reservation = $this->reservations->reserve(
                            $businessId,
                            'marketplace_quote',
                            $quote->quote_number,
                            $lines
                                ->map(fn (MarketplaceQuoteLine $line): array => [
                                    'product_external_id' => $line->product_external_id,
                                    'warehouse_external_id' => $line->warehouse_external_id,
                                    'quantity' => (float) $line->quantity,
                                ])
                                ->values()
                                ->all(),
                            $quote->expires_at,
                        );
                    } catch (InsufficientInventoryAvailable $exception) {
                        throw ValidationException::withMessages([
                            'quote' => $exception->getMessage(),
                        ]);
                    }

                    $reservationIds[] = $reservation->id;
                });

            $snapshot = $quote->payload_snapshot ?? [];
            $snapshot['reservation_ids'] = $reservationIds;

            $quote->forceFill([
                'status' => MarketplaceQuote::StatusReserved,
                'payload_snapshot' => $snapshot,
            ])->save();

            return $reservationIds;
        });

        $quote = $quote->refresh()->load('lines');

        MarketplaceQuoteReserved::dispatch($quote, $reservationIds);

        return new MarketplaceReservationResult($quote, $reservationIds);
    }

    public function confirm(MarketplaceConfirmationRequest $request): MarketplaceConfirmationResult
    {
        $quote = $request->quote->loadMissing('lines');

        if ($existing = MasterOrder::query()->where('marketplace_quote_id', $quote->id)->with(['sellerOrders.lines', 'statusHistory'])->first()) {
            return new MarketplaceConfirmationResult($existing);
        }

        if ($quote->status !== MarketplaceQuote::StatusReserved || $quote->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'quote' => 'La cotizacion debe estar reservada y vigente para crear la orden.',
            ]);
        }

        $reservationIds = $quote->payload_snapshot['reservation_ids'] ?? [];
        if (! is_array($reservationIds) || $reservationIds === []) {
            throw ValidationException::withMessages([
                'quote' => 'La cotizacion no tiene reservas asociadas.',
            ]);
        }

        $masterOrder = DB::transaction(function () use ($quote, $request, $reservationIds): MasterOrder {
            $reservations = InventoryReservation::query()
                ->whereIn('id', $reservationIds)
                ->where('status', InventoryReservation::StatusActive)
                ->lockForUpdate()
                ->get()
                ->keyBy('business_id');

            $businessIds = $quote->lines->pluck('business_id')->unique()->values();
            if ($reservations->count() !== $businessIds->count()) {
                throw ValidationException::withMessages([
                    'quote' => 'No todas las reservas de la cotizacion estan activas.',
                ]);
            }

            $masterOrder = MasterOrder::query()->create([
                'marketplace_quote_id' => $quote->id,
                'order_number' => 'MO-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                'consumer_id' => $quote->consumer_id,
                'status' => MasterOrder::StatusConfirmed,
                'payment_status' => $request->paymentStatus,
                'delivery_status' => $request->deliveryStatus,
                'recipient_snapshot' => $request->recipientSnapshot,
                'delivery_address_snapshot' => $request->deliveryAddressSnapshot,
                'payment_snapshot' => $request->paymentSnapshot,
                'delivery_snapshot' => $request->deliverySnapshot,
                'subtotal' => $quote->subtotal,
                'delivery_total' => $quote->delivery_total,
                'fees_total' => $quote->fees_total,
                'grand_total' => $quote->grand_total,
                'currency' => $quote->currency,
            ]);

            MarketplaceOrderStatusHistory::query()->create([
                'master_order_id' => $masterOrder->id,
                'from_status' => null,
                'to_status' => MasterOrder::StatusConfirmed,
                'actor_type' => 'marketplace',
                'actor_id' => $quote->quote_number,
                'notes' => 'Orden creada desde cotizacion Marketplace.',
            ]);

            $quote->lines
                ->groupBy('business_id')
                ->each(function ($lines, int $businessId) use ($masterOrder, $reservations): void {
                    /** @var InventoryReservation $reservation */
                    $reservation = $reservations->get($businessId);
                    $subtotal = (float) $lines->sum(fn (MarketplaceQuoteLine $line): float => (float) $line->subtotal);
                    $currency = (string) $lines->first()->currency;

                    $sellerOrder = SellerOrder::query()->create([
                        'master_order_id' => $masterOrder->id,
                        'business_id' => $businessId,
                        'seller_order_number' => 'SO-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                        'status' => SellerOrder::StatusReserved,
                        'reservation_id' => $reservation->id,
                        'subtotal' => $subtotal,
                        'currency' => $currency,
                    ]);

                    MarketplaceOrderStatusHistory::query()->create([
                        'master_order_id' => $masterOrder->id,
                        'seller_order_id' => $sellerOrder->id,
                        'from_status' => null,
                        'to_status' => SellerOrder::StatusReserved,
                        'actor_type' => 'marketplace',
                        'actor_id' => $masterOrder->order_number,
                        'notes' => 'Orden de vendedor creada desde cotizacion reservada.',
                    ]);

                    $lines->each(function (MarketplaceQuoteLine $line) use ($sellerOrder): void {
                        $sellerOrder->lines()->create([
                            'product_external_id' => $line->product_external_id,
                            'warehouse_external_id' => $line->warehouse_external_id,
                            'title_snapshot' => $line->title_snapshot,
                            'unit_price' => $line->unit_price,
                            'quantity' => $line->quantity,
                            'subtotal' => $line->subtotal,
                        ]);
                    });
                });

            $snapshot = $quote->payload_snapshot ?? [];
            $snapshot['master_order_id'] = $masterOrder->id;

            $quote->forceFill([
                'status' => MarketplaceQuote::StatusConverted,
                'payload_snapshot' => $snapshot,
            ])->save();

            return $masterOrder->refresh()->load(['sellerOrders.lines', 'statusHistory']);
        });

        MarketplaceOrderConfirmed::dispatch($masterOrder);

        return new MarketplaceConfirmationResult($masterOrder);
    }

    /**
     * @param  array<int, array{publication_id:int, quantity:float}>  $lines
     * @return array<int, float>
     */
    private function aggregateRequestedLines(array $lines): array
    {
        $aggregated = [];

        foreach ($lines as $line) {
            $publicationId = (int) ($line['publication_id'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);

            if ($publicationId <= 0 || $quantity <= 0.0) {
                continue;
            }

            $aggregated[$publicationId] = ($aggregated[$publicationId] ?? 0.0) + $quantity;
        }

        return $aggregated;
    }
}
