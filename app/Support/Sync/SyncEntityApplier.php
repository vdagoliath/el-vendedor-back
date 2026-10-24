<?php

namespace App\Support\Sync;

use App\Models\Business;
use App\Models\CatalogProduct;
use App\Models\CatalogStorefront;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\MarketplaceProductPublication;
use App\Models\MetricsSnapshot;
use App\Models\PointOfSale;
use App\Models\ProductBatch;
use App\Models\RestaurantLayout;
use App\Models\SyncReceivedEvent;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Models\WeightJournal;
use App\Modules\Catalog\CatalogService;
use App\Support\CatalogImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SyncEntityApplier
{
    /**
     * Apply a sync change to the corresponding materialized entity table.
     * Returns true if the entity was materialized, false if entity_type is not supported here.
     */
    public function apply(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return match ($change['entity_type']) {
            'categories' => $this->applyCategory($business, $event, $change),
            'contacts' => $this->applyContact($business, $event, $change),
            'employees' => $this->applyEmployee($business, $event, $change),
            'units' => $this->applyUnit($business, $event, $change),
            'warehouses' => $this->applyWarehouse($business, $event, $change),
            'points_of_sale' => $this->applyPointOfSale($business, $event, $change),
            'marketplace_product_publications' => $this->applyMarketplaceProductPublication($business, $event, $change),
            'catalog_storefront' => $this->applyCatalogStorefront($business, $event, $change),
            'catalog_products' => $this->applyCatalogProduct($business, $event, $change),
            'product_batches' => $this->applyProductBatch($business, $event, $change),
            'metrics_snapshots' => $this->applyMetricsSnapshot($business, $event, $change),
            'weight_journals' => $this->applyWeightJournal($business, $event, $change),
            'restaurant_layouts' => $this->applyRestaurantLayout($business, $event, $change),
            'invoices' => $this->applyInvoice($business, $event, $change),
            default => false,
        };
    }

    private function applyCategory(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Category::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'description' => $this->nullableString($payload['description'] ?? null),
                'code' => $this->nullableString($payload['code'] ?? null),
                'color' => $this->nullableString($payload['color'] ?? null),
                'icon' => $this->nullableString($payload['icon'] ?? null),
                'parent_external_id' => $this->nullableString($payload['parentId'] ?? null),
            ]
        );
    }

    private function applyContact(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Contact::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'mobile' => $this->nullableString($payload['mobile'] ?? null),
                'email' => $this->nullableString($payload['email'] ?? null),
                'id_card' => $this->nullableString($payload['idCard'] ?? null),
                'type' => strtolower(trim((string) ($payload['type'] ?? 'customer'))),
                'category' => $this->nullableString($payload['category'] ?? null),
                'status' => $this->nullableString($payload['status'] ?? null),
            ]
        );
    }

    private function applyEmployee(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Employee::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'mobile' => $this->nullableString($payload['mobile'] ?? null),
                'role' => $this->nullableString($payload['role'] ?? null),
            ]
        );
    }

    private function applyUnit(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            UnitOfMeasure::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'symbol' => trim((string) ($payload['symbol'] ?? '')),
                'category' => $this->nullableString($payload['category'] ?? null),
                'ratio' => is_numeric($payload['ratio'] ?? null) ? (float) $payload['ratio'] : 1,
                'is_reference' => (bool) ($payload['is_reference'] ?? false),
            ]
        );
    }

    private function applyWarehouse(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Warehouse::class,
            $business,
            $event,
            $change,
            function (array $payload): array {
                $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

                return [
                    'name' => trim((string) ($payload['name'] ?? '')),
                    'country' => $this->nullableString($address['country'] ?? null),
                    'province' => $this->nullableString($address['province'] ?? null),
                    'municipality' => $this->nullableString($address['municipality'] ?? null),
                    'street' => $this->nullableString($address['street'] ?? null),
                ];
            }
        );
    }

    private function applyPointOfSale(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            PointOfSale::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'type' => $this->normalizePointOfSaleType($payload['type'] ?? null),
                'warehouse_external_id' => $this->nullableString($payload['warehouseId'] ?? null),
                'employees' => is_array($payload['employees'] ?? null) ? $payload['employees'] : null,
            ]
        );
    }

    /**
     * Normaliza el tipo de punto de venta aceptado por el sync.
     */
    private function normalizePointOfSaleType(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return in_array($normalized, ['regular', 'restaurant'], true) ? $normalized : 'regular';
    }

    private function applyProductBatch(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            ProductBatch::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'product_external_id' => trim((string) ($payload['productId'] ?? '')),
                'warehouse_external_id' => trim((string) ($payload['warehouseId'] ?? '')),
                'batch_code' => $this->nullableString($payload['batchCode'] ?? null),
                'quantity' => is_numeric($payload['quantity'] ?? null) ? (float) $payload['quantity'] : 0,
                'remaining_quantity' => is_numeric($payload['remainingQuantity'] ?? null)
                    ? (float) $payload['remainingQuantity']
                    : (is_numeric($payload['quantity'] ?? null) ? (float) $payload['quantity'] : 0),
                'expiration_date' => $this->parseDate($payload['expirationDate'] ?? null)?->toDateString(),
                'received_at' => $this->parseDate($payload['receivedAt'] ?? null),
                'source' => $this->nullableString($payload['source'] ?? null),
                'source_id' => $this->nullableString($payload['sourceId'] ?? null),
            ]
        );
    }

    private function applyMarketplaceProductPublication(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $productExternalId = trim((string) ($payload['productId'] ?? $change['entity_id'] ?? ''));

        if ($productExternalId === '') {
            throw new \RuntimeException('La publicacion Marketplace sincronizada no tiene producto valido.');
        }

        if (($change['operation'] ?? 'upsert') === 'delete') {
            MarketplaceProductPublication::query()
                ->where('business_id', $business->id)
                ->where('product_external_id', $productExternalId)
                ->delete();

            return true;
        }

        $status = trim((string) ($payload['status'] ?? MarketplaceProductPublication::StatusDraft));
        if (! in_array($status, [
            MarketplaceProductPublication::StatusDraft,
            MarketplaceProductPublication::StatusPublished,
            MarketplaceProductPublication::StatusPaused,
            MarketplaceProductPublication::StatusArchived,
        ], true)) {
            $status = MarketplaceProductPublication::StatusDraft;
        }

        $images = $this->marketplacePublicationImages($business, $productExternalId, $payload);

        MarketplaceProductPublication::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'product_external_id' => $productExternalId,
            ],
            [
                'warehouse_external_id' => trim((string) ($payload['warehouseId'] ?? '')),
                'status' => $status,
                'public_title' => trim((string) ($payload['publicTitle'] ?? '')),
                'public_description' => $this->nullableString($payload['publicDescription'] ?? null),
                'public_price' => is_numeric($payload['publicPrice'] ?? null) ? (float) $payload['publicPrice'] : 0,
                'currency' => strtoupper(trim((string) ($payload['currency'] ?? 'CUP'))),
                'images' => $images,
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            ]
        );

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function marketplacePublicationImages(Business $business, string $productExternalId, array $payload): array
    {
        $publicImages = collect(is_array($payload['images'] ?? null) ? $payload['images'] : [])
            ->filter(fn ($image): bool => is_string($image) && preg_match('/^https?:\/\//i', $image) === 1)
            ->values()
            ->all();

        $uploads = is_array($payload['imageUploads'] ?? null) ? $payload['imageUploads'] : [];
        foreach ($uploads as $upload) {
            if (! is_array($upload)) {
                continue;
            }

            $url = $this->storeMarketplacePublicationImage($business, $productExternalId, $upload);
            if ($url !== null) {
                $publicImages[] = $url;
            }
        }

        return array_values(array_unique($publicImages));
    }

    private function storeMarketplacePublicationImage(Business $business, string $productExternalId, array $upload): ?string
    {
        $rawData = trim((string) ($upload['data'] ?? ''));
        if ($rawData === '') {
            return null;
        }

        if (str_contains($rawData, ',')) {
            $rawData = substr($rawData, strpos($rawData, ',') + 1);
        }

        $binary = base64_decode($rawData, true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }

        $contentType = strtolower(trim((string) ($upload['contentType'] ?? 'image/jpeg')));
        $extension = match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $safeProductId = Str::slug($productExternalId) ?: 'product';
        $path = sprintf(
            'marketplace/business-%s/products/%s/%s.%s',
            $business->id,
            $safeProductId,
            Str::ulid(),
            $extension,
        );

        Storage::disk('public')->put($path, $binary);

        return Storage::disk('public')->url($path);
    }

    /**
     * Catálogo online: configuración pública del negocio (singleton por negocio).
     */
    private function applyCatalogStorefront(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        if (($change['operation'] ?? 'upsert') === 'delete') {
            CatalogStorefront::query()->where('business_id', $business->id)->delete();

            return true;
        }

        $existing = CatalogStorefront::query()->where('business_id', $business->id)->first();
        $catalog = app(CatalogService::class);

        $subdomain = strtolower(trim((string) ($payload['subdomain'] ?? $existing?->subdomain ?? '')));
        if ($subdomain === '') {
            $subdomain = $catalog->uniqueSubdomain((string) ($business->slug ?: $business->name), $business->id);
        }

        $isPublished = (bool) ($payload['isPublished'] ?? $existing?->is_published ?? false);
        $whatsapp = preg_replace('/\D+/', '', (string) ($payload['whatsapp'] ?? $existing?->whatsapp ?? '')) ?? '';

        $attributes = [
            'subdomain' => $subdomain,
            'display_name' => trim((string) ($payload['displayName'] ?? $existing?->display_name ?? $business->name)),
            'tagline' => $this->nullableString($payload['tagline'] ?? null),
            'about' => $this->nullableString($payload['about'] ?? null),
            'accent_color' => $this->nullableString($payload['accentColor'] ?? null) ?? '#0F766E',
            'accent_contrast' => $this->nullableString($payload['accentContrast'] ?? null),
            'whatsapp' => $whatsapp,
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'email' => $this->nullableString($payload['email'] ?? null),
            'address' => $this->nullableString($payload['address'] ?? null),
            'city' => $this->nullableString($payload['city'] ?? null),
            'hours' => $this->catalogHours($payload['hours'] ?? null),
            'social' => $this->catalogSocial($payload['social'] ?? null),
            'currency' => strtoupper(trim((string) ($payload['currency'] ?? $existing?->currency ?? 'CUP'))),
            'currency_symbol' => $this->nullableString($payload['currencySymbol'] ?? null),
            'delivery_note' => $this->nullableString($payload['deliveryNote'] ?? null),
            'payment_note' => $this->nullableString($payload['paymentNote'] ?? null),
            'cover_url' => $this->catalogStorefrontImage($business, 'cover', $payload['coverUpload'] ?? null) ?? $existing?->cover_url,
            'logo_url' => $this->catalogStorefrontImage($business, 'logo', $payload['logoUpload'] ?? null) ?? $existing?->logo_url,
            'is_published' => $isPublished,
            'published_at' => $isPublished ? ($existing?->published_at ?? now()) : null,
        ];

        if ($existing instanceof CatalogStorefront) {
            $existing->fill($attributes)->save();
        } else {
            CatalogStorefront::query()->create(['business_id' => $business->id] + $attributes);
        }

        return true;
    }

    /**
     * Catálogo online: un producto por `product_external_id`, con el precio que
     * definió el dueño (nunca el precio del marketplace).
     */
    private function applyCatalogProduct(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $productExternalId = trim((string) ($payload['productId'] ?? $change['entity_id'] ?? ''));

        if ($productExternalId === '') {
            throw new \RuntimeException('El producto de catalogo sincronizado no tiene identificador.');
        }

        if (($change['operation'] ?? 'upsert') === 'delete') {
            $current = CatalogProduct::query()
                ->where('business_id', $business->id)
                ->where('product_external_id', $productExternalId)
                ->first();

            foreach ($current?->images ?? [] as $url) {
                CatalogImage::delete(is_string($url) ? $url : null);
            }

            CatalogProduct::query()
                ->where('business_id', $business->id)
                ->where('product_external_id', $productExternalId)
                ->delete();

            return true;
        }

        $existing = CatalogProduct::query()
            ->where('business_id', $business->id)
            ->where('product_external_id', $productExternalId)
            ->first();

        $title = trim((string) ($payload['title'] ?? $existing?->title ?? 'Producto'));

        // El slug se fija al crear y no cambia al renombrar: los enlaces del
        // catálogo publicado no deben romperse.
        $slug = $existing?->slug ?: app(CatalogService::class)->uniqueProductSlug($business->id, $title);

        // Sin claves de imágenes en el payload se conservan las que ya tenía.
        $previousImages = array_values(array_filter($existing?->images ?? [], 'is_string'));
        $hasImages = array_key_exists('images', $payload) || array_key_exists('imageUploads', $payload);
        $images = $hasImages
            ? $this->catalogProductImages($business, $productExternalId, $payload)
            : $previousImages;

        $attributes = [
            'slug' => $slug,
            'title' => $title,
            'description' => $this->nullableString($payload['description'] ?? null),
            'price' => is_numeric($payload['price'] ?? null) ? (float) $payload['price'] : 0.0,
            'currency' => strtoupper(trim((string) ($payload['currency'] ?? $existing?->currency ?? 'CUP'))),
            'unit' => $this->nullableString($payload['unit'] ?? null),
            'badge' => $this->nullableString($payload['badge'] ?? null),
            'category_name' => $this->nullableString($payload['categoryName'] ?? null),
            'category_emoji' => $this->nullableString($payload['categoryEmoji'] ?? null),
            'images' => $images,
            'is_visible' => (bool) ($payload['isVisible'] ?? true),
            'is_featured' => (bool) ($payload['isFeatured'] ?? false),
            'is_available' => (bool) ($payload['isAvailable'] ?? true),
            'sort' => max(0, (int) ($payload['sort'] ?? 0)),
        ];

        if ($existing instanceof CatalogProduct) {
            $existing->fill($attributes)->save();
        } else {
            CatalogProduct::query()->create([
                'business_id' => $business->id,
                'product_external_id' => $productExternalId,
            ] + $attributes);
        }

        // Las imágenes que dejaron de referenciarse no se quedan en el disco.
        foreach (array_diff($previousImages, $images) as $removed) {
            CatalogImage::delete((string) $removed);
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogHours(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($slot): bool => is_array($slot) && isset($slot['day']) && is_numeric($slot['day']))
            ->map(fn (array $slot): array => [
                'day' => max(0, min(6, (int) $slot['day'])),
                'open' => $this->normalizeCatalogTime($slot['open'] ?? null),
                'close' => $this->normalizeCatalogTime($slot['close'] ?? null),
                'closed' => (bool) ($slot['closed'] ?? false),
            ])
            ->values()
            ->all();
    }

    private function normalizeCatalogTime(mixed $value): string
    {
        $time = trim((string) $value);

        return preg_match('/^\d{2}:\d{2}$/', $time) === 1 ? $time : '09:00';
    }

    /**
     * @return array<string, string>
     */
    private function catalogSocial(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $social = [];
        foreach (['instagram', 'facebook', 'telegram'] as $network) {
            $url = $this->nullableString($value[$network] ?? null);
            if ($url !== null) {
                $social[$network] = $url;
            }
        }

        return $social;
    }

    private function catalogStorefrontImage(Business $business, string $kind, mixed $payload): ?string
    {
        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        return CatalogImage::store($business, $kind, $payload);
    }

    /**
     * Imágenes del producto: las URLs ya públicas más las subidas en este cambio.
     *
     * @return array<int, string>
     */
    private function catalogProductImages(Business $business, string $productExternalId, array $payload): array
    {
        $images = collect(is_array($payload['images'] ?? null) ? $payload['images'] : [])
            ->filter(fn ($image): bool => is_string($image) && trim($image) !== '')
            ->map(fn (string $image): string => trim($image))
            ->values()
            ->all();

        $uploads = is_array($payload['imageUploads'] ?? null) ? $payload['imageUploads'] : [];
        foreach ($uploads as $upload) {
            if (! is_array($upload)) {
                continue;
            }

            $url = CatalogImage::storeProductImage($business, $productExternalId, $upload);
            if ($url !== null) {
                $images[] = $url;
            }
        }

        return array_slice(array_values(array_unique($images)), 0, CatalogImage::MAX_PRODUCT_IMAGES);
    }

    private function applyMetricsSnapshot(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            MetricsSnapshot::class,
            $business,
            $event,
            $change,
            function (array $payload): array {
                return [
                    'period' => trim((string) ($payload['period'] ?? 'day')) ?: 'day',
                    'period_start' => $this->parseDate($payload['periodStart'] ?? $payload['period_start'] ?? null)?->toDateString() ?? now()->toDateString(),
                    'period_end' => $this->parseDate($payload['periodEnd'] ?? $payload['period_end'] ?? $payload['periodStart'] ?? null)?->toDateString() ?? now()->toDateString(),
                    'generated_at' => $this->parseDate($payload['generatedAt'] ?? $payload['generated_at'] ?? null),
                    'source_runs' => is_array($payload['sourceRuns'] ?? null) ? array_values($payload['sourceRuns']) : [],
                    'source_counts' => is_array($payload['sourceCounts'] ?? null) ? $payload['sourceCounts'] : ['bills' => 0, 'expenses' => 0],
                    'totals' => is_array($payload['totals'] ?? null) ? $payload['totals'] : [],
                    'products' => is_array($payload['products'] ?? null) ? array_values($payload['products']) : [],
                    'expense_categories' => is_array($payload['expenseCategories'] ?? null) ? array_values($payload['expenseCategories']) : [],
                ];
            }
        );
    }

    private function applyWeightJournal(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            WeightJournal::class,
            $business,
            $event,
            $change,
            function (array $payload) use ($business, $change): array {
                $status = trim((string) ($payload['status'] ?? 'open')) ?: 'open';
                $sessionExternalId = trim((string) ($payload['cashRegisterSessionId'] ?? ''));

                if ($status === 'open' && $sessionExternalId !== '') {
                    $hasAnotherOpenJournal = WeightJournal::query()
                        ->where('business_id', $business->id)
                        ->where('cash_register_session_external_id', $sessionExternalId)
                        ->where('status', 'open')
                        ->where('external_id', '!=', $change['entity_id'])
                        ->exists();

                    if ($hasAnotherOpenJournal) {
                        throw new \RuntimeException('Ya existe una jornada por peso abierta para esta sesión de caja.');
                    }
                }

                return [
                    'status' => $status,
                    'opened_at' => $this->parseDate($payload['openedAt'] ?? null),
                    'closed_at' => $this->parseDate($payload['closedAt'] ?? null),
                    'pos_external_id' => trim((string) ($payload['posId'] ?? '')),
                    'pos_name' => $this->nullableString($payload['posName'] ?? null),
                    'cash_register_session_external_id' => $sessionExternalId,
                    'warehouse_external_id' => trim((string) ($payload['warehouseId'] ?? '')),
                    'payment_method' => $this->nullableString($payload['paymentMethod'] ?? null),
                    'payment_breakdown' => is_array($payload['paymentBreakdown'] ?? null) ? array_values($payload['paymentBreakdown']) : [],
                    'items' => is_array($payload['items'] ?? null) ? array_values($payload['items']) : [],
                    'total_sold_quantity' => $this->decimal($payload['totalSoldQuantity'] ?? 0),
                    'total_loss_quantity' => $this->decimal($payload['totalLossQuantity'] ?? 0),
                    'total' => $this->decimal($payload['total'] ?? 0),
                    'sale_external_id' => $this->nullableString($payload['saleId'] ?? null),
                    'sale_reference' => $this->nullableString($payload['saleReference'] ?? null),
                    'notes' => $this->nullableString($payload['notes'] ?? null),
                ];
            }
        );
    }

    /**
     * Generic upsert-or-delete for any entity with (business_id, external_id) unique key.
     *
     * @param  class-string  $modelClass
     * @param  callable(array): array  $mapPayload
     */
    /**
     * Plano de salon (salones + mesas) publicado para un punto de venta.
     */
    private function applyRestaurantLayout(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            RestaurantLayout::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'pos_name' => $this->nullableString($payload['posName'] ?? null),
                'salons' => $this->normalizeLayoutCollection($payload['salons'] ?? []),
                'tables' => $this->normalizeLayoutCollection($payload['tables'] ?? []),
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeLayoutCollection(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * Materializa una factura con sus lineas.
     *
     * Dos reglas del contrato que el servidor respeta:
     *  - nunca renumera: `series` y `number` vienen del equipo emisor;
     *  - un numero no se reutiliza: si otro documento del negocio ya ocupa
     *    `(series, number)`, el cambio se rechaza con un mensaje claro en lugar de
     *    sobrescribir la factura existente.
     *
     * El payload ya trae una copia congelada del emisor, el receptor, los totales
     * y la huella, asi que aqui no se recalcula nada.
     */
    private function applyInvoice(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $entityId = (string) $change['entity_id'];
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $series = strtoupper(trim((string) ($payload['series'] ?? '')));
        $number = (int) ($payload['number'] ?? 0);

        if ($change['operation'] !== 'delete') {
            if ($series === '' || $number < 1) {
                throw new \RuntimeException('La factura necesita serie y numero.');
            }

            $taken = Invoice::query()
                ->withTrashed()
                ->where('business_id', $business->id)
                ->where('series', $series)
                ->where('number', $number)
                ->where('external_id', '!=', $entityId)
                ->exists();

            if ($taken) {
                throw new \RuntimeException("El numero {$series}-{$number} ya existe en esta serie para este negocio.");
            }
        }

        $applied = $this->upsertOrDelete(
            Invoice::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'contract_version' => (int) ($payload['contractVersion'] ?? 1),
                'document_type' => (string) ($payload['documentType'] ?? 'invoice'),
                'series' => strtoupper(trim((string) ($payload['series'] ?? ''))),
                'number' => (int) ($payload['number'] ?? 0),
                'number_padding' => (int) ($payload['numberPadding'] ?? 8),
                'display_number' => trim((string) ($payload['displayNumber'] ?? '')),
                'number_source' => (string) ($payload['numberSource'] ?? 'local'),
                'sale_external_id' => $this->nullableString($payload['saleId'] ?? null),
                'original_invoice_external_id' => $this->nullableString($payload['originalInvoiceId'] ?? null),
                'issued_at' => $this->parseDate($payload['issuedAt'] ?? null),
                'status' => (string) ($payload['status'] ?? 'issued'),
                'currency' => $this->nullableString($payload['currency'] ?? null),
                'issuer' => is_array($payload['issuer'] ?? null) ? $payload['issuer'] : null,
                'customer' => is_array($payload['customer'] ?? null) ? $payload['customer'] : null,
                'totals' => is_array($payload['totals'] ?? null) ? $payload['totals'] : null,
                'payment' => is_array($payload['payment'] ?? null) ? $payload['payment'] : null,
                'hash' => $this->nullableString($payload['hash'] ?? null),
                'reprint_count' => (int) ($payload['reprintCount'] ?? 0),
                'footer' => $this->nullableString($payload['footer'] ?? null),
                'voided_at' => $this->parseDate($payload['voidedAt'] ?? null),
                'voided_by' => is_array($payload['voidedBy'] ?? null) ? $payload['voidedBy'] : null,
                'void_reason' => $this->nullableString($payload['voidReason'] ?? null),
                'created_by' => is_array($payload['createdBy'] ?? null) ? $payload['createdBy'] : null,
            ]
        );

        if (! $applied) {
            return false;
        }

        if ($change['operation'] !== 'delete') {
            $this->replaceInvoiceLines($business, $entityId, $payload);
            $this->syncInvoiceSeries($business, $entityId, $payload);
        }

        return true;
    }

    /**
     * Las lineas se reemplazan completas: una factura no se edita linea a linea.
     *
     * @param  array<string, mixed>  $payload
     */
    private function replaceInvoiceLines(Business $business, string $entityId, array $payload): void
    {
        $invoice = Invoice::query()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if (! $invoice) {
            return;
        }

        $invoice->lines()->delete();

        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $order = 0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $invoice->lines()->create([
                'product_external_id' => $this->nullableString($line['productId'] ?? null),
                'product_title' => $this->nullableString($line['productTitle'] ?? null),
                'quantity' => $this->decimal($line['quantity'] ?? 0),
                'unit_price' => $this->decimal($line['unitPrice'] ?? 0),
                'line_total' => $this->decimal($line['lineTotal'] ?? 0),
                'unit_symbol' => $this->nullableString($line['unitSymbol'] ?? null),
                'sort_order' => $order,
            ]);

            $order++;
        }
    }

    /**
     * Refleja en el servidor el avance de la serie, sin renumerar nada. Es la
     * vista que permite auditar y detectar huecos.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncInvoiceSeries(Business $business, string $entityId, array $payload): void
    {
        $series = strtoupper(trim((string) ($payload['series'] ?? '')));
        $number = (int) ($payload['number'] ?? 0);

        if ($series === '' || $number < 1) {
            return;
        }

        $counter = InvoiceSeries::query()->firstOrNew([
            'business_id' => $business->id,
            'series' => $series,
        ]);

        $counter->padding = (int) ($payload['numberPadding'] ?? 8);
        $counter->next_number = max((int) ($counter->next_number ?? 1), $number + 1);
        $counter->last_number = max((int) ($counter->last_number ?? 0), $number);
        $counter->last_invoice_external_id = $entityId;
        $counter->last_issued_at = $this->parseDate($payload['issuedAt'] ?? null) ?? now();
        $counter->save();
    }

    private function upsertOrDelete(string $modelClass, Business $business, SyncReceivedEvent $event, array $change, callable $mapPayload): bool
    {
        $entityId = $change['entity_id'];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\SoftDeletes|null $record */
        $record = $modelClass::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if ($operation === 'delete') {
            if (! $record) {
                return true;
            }

            $record->forceFill([
                'last_received_event_id' => $event->event_id,
                'source_updated_at' => $occurredAt ?? now(),
            ])->save();

            if (! $record->trashed()) {
                $record->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $fields = $mapPayload($payload);

        if (! $record) {
            $record = new $modelClass([
                'business_id' => $business->id,
                'external_id' => $entityId,
            ]);
        }

        $record->fill(array_merge($fields, [
            'business_id' => $business->id,
            'external_id' => $entityId,
            'source_created_at' => $record->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]));

        $wasTrashed = method_exists($record, 'trashed') && $record->trashed();
        $record->save();

        if ($wasTrashed) {
            $record->restore();
        }

        return true;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
