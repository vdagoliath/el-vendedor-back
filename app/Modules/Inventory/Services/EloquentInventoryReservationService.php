<?php

namespace App\Modules\Inventory\Services;

use App\Events\InventoryReservationExpired;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationLine;
use App\Models\StockProjection;
use App\Modules\Inventory\Contracts\InventoryReservationService;
use App\Modules\Inventory\Exceptions\InsufficientInventoryAvailable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EloquentInventoryReservationService implements InventoryReservationService
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
    ): InventoryReservation {
        $normalizedLines = $this->normalizeLines($lines);

        if ($normalizedLines === []) {
            throw new InvalidArgumentException('Inventory reservations require at least one valid line.');
        }

        $expiresAt = $this->parseExpiration($expiresAt);

        return DB::transaction(function () use ($businessId, $ownerType, $ownerId, $normalizedLines, $expiresAt): InventoryReservation {
            $existing = InventoryReservation::query()
                ->with('lines')
                ->where('business_id', $businessId)
                ->where('owner_type', trim($ownerType))
                ->where('owner_id', (string) $ownerId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === InventoryReservation::StatusActive) {
                return $existing;
            }

            $this->lockProjectionRows($businessId, $normalizedLines);
            $activeReservations = $this->activeReservationLines($businessId, $normalizedLines);

            foreach ($normalizedLines as $line) {
                $physical = $this->physicalQuantity($businessId, $line);
                $reserved = $this->reservedQuantity($activeReservations, $line);
                $available = $physical - $reserved;

                if ($available < $line['quantity']) {
                    throw new InsufficientInventoryAvailable(
                        $businessId,
                        $line['product_external_id'],
                        $line['quantity'],
                        $available,
                        $line['warehouse_external_id'],
                    );
                }
            }

            if ($existing) {
                $existing->lines()->delete();
                $existing->forceFill([
                    'status' => InventoryReservation::StatusActive,
                    'expires_at' => $expiresAt,
                    'confirmed_at' => null,
                    'released_at' => null,
                    'expired_at' => null,
                    'cancelled_at' => null,
                ])->save();

                $reservation = $existing;
            } else {
                $reservation = InventoryReservation::query()->create([
                    'business_id' => $businessId,
                    'owner_type' => trim($ownerType),
                    'owner_id' => (string) $ownerId,
                    'status' => InventoryReservation::StatusActive,
                    'expires_at' => $expiresAt,
                ]);
            }

            foreach ($normalizedLines as $line) {
                $reservation->lines()->create([
                    'business_id' => $businessId,
                    'product_external_id' => $line['product_external_id'],
                    'warehouse_external_id' => $line['warehouse_external_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            return $reservation->refresh()->load('lines');
        });
    }

    public function confirm(InventoryReservation|int $reservation): InventoryReservation
    {
        return $this->transition($reservation, InventoryReservation::StatusConfirmed, 'confirmed_at');
    }

    public function release(InventoryReservation|int $reservation): InventoryReservation
    {
        return $this->transition($reservation, InventoryReservation::StatusReleased, 'released_at');
    }

    public function cancel(InventoryReservation|int $reservation): InventoryReservation
    {
        return $this->transition($reservation, InventoryReservation::StatusCancelled, 'cancelled_at');
    }

    public function expire(InventoryReservation|int $reservation): InventoryReservation
    {
        [$expiredReservation, $wasExpired] = DB::transaction(function () use ($reservation): array {
            $model = $reservation instanceof InventoryReservation
                ? InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id)
                : InventoryReservation::query()->lockForUpdate()->findOrFail($reservation);

            if ($model->status !== InventoryReservation::StatusActive) {
                return [$model->refresh(), false];
            }

            $model->forceFill([
                'status' => InventoryReservation::StatusExpired,
                'expired_at' => now(),
            ])->save();

            return [$model->refresh(), true];
        });

        if ($wasExpired) {
            InventoryReservationExpired::dispatch($expiredReservation);
        }

        return $expiredReservation;
    }

    public function expirePastDue(?int $limit = null): int
    {
        $query = InventoryReservation::query()
            ->where('status', InventoryReservation::StatusActive)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $expired = 0;

        $query->get(['id'])->each(function (InventoryReservation $reservation) use (&$expired): void {
            $this->expire($reservation->id);
            $expired++;
        });

        return $expired;
    }

    /**
     * @param  array<int, array{product_external_id:string, quantity:float|int|string, warehouse_external_id?:string|null}>  $lines
     * @return array<int, array{product_external_id:string, warehouse_external_id:string|null, quantity:float}>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $productExternalId = trim($line['product_external_id'] ?? '');
            $quantity = $line['quantity'] ?? 0;

            if ($productExternalId === '' || ! is_numeric($quantity) || (float) $quantity <= 0.0) {
                continue;
            }

            $warehouseExternalId = isset($line['warehouse_external_id']) && is_string($line['warehouse_external_id'])
                ? trim($line['warehouse_external_id'])
                : null;

            $warehouseExternalId = $warehouseExternalId === '' ? null : $warehouseExternalId;
            $key = $productExternalId.'|'.($warehouseExternalId ?? '*');

            if (! isset($normalized[$key])) {
                $normalized[$key] = [
                    'product_external_id' => $productExternalId,
                    'warehouse_external_id' => $warehouseExternalId,
                    'quantity' => 0.0,
                ];
            }

            $normalized[$key]['quantity'] += (float) $quantity;
        }

        return array_values($normalized);
    }

    private function parseExpiration(DateTimeInterface|string|null $expiresAt): Carbon
    {
        if ($expiresAt === null) {
            return now()->addMinutes(15);
        }

        return Carbon::parse($expiresAt);
    }

    /**
     * @param  array<int, array{product_external_id:string, warehouse_external_id:string|null, quantity:float}>  $lines
     */
    private function lockProjectionRows(int $businessId, array $lines): void
    {
        StockProjection::query()
            ->where('business_id', $businessId)
            ->where(function (Builder $query) use ($lines): void {
                foreach ($lines as $line) {
                    $query->orWhere(function (Builder $query) use ($line): void {
                        $query->where('product_external_id', $line['product_external_id']);

                        if ($line['warehouse_external_id'] !== null) {
                            $query->where('warehouse_external_id', $line['warehouse_external_id']);
                        }
                    });
                }
            })
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * @param  array<int, array{product_external_id:string, warehouse_external_id:string|null, quantity:float}>  $lines
     * @return Collection<int, InventoryReservationLine>
     */
    private function activeReservationLines(int $businessId, array $lines): Collection
    {
        return InventoryReservationLine::query()
            ->with('reservation:id,status,expires_at')
            ->where('business_id', $businessId)
            ->where(function (Builder $query) use ($lines): void {
                foreach ($lines as $line) {
                    $query->orWhere(function (Builder $query) use ($line): void {
                        $query->where('product_external_id', $line['product_external_id']);

                        if ($line['warehouse_external_id'] !== null) {
                            $query->where('warehouse_external_id', $line['warehouse_external_id']);
                        }
                    });
                }
            })
            ->whereHas('reservation', function (Builder $query): void {
                $query
                    ->where('status', InventoryReservation::StatusActive)
                    ->where('expires_at', '>', now());
            })
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array{product_external_id:string, warehouse_external_id:string|null, quantity:float}  $line
     */
    private function physicalQuantity(int $businessId, array $line): float
    {
        $query = StockProjection::query()
            ->where('business_id', $businessId)
            ->where('product_external_id', $line['product_external_id']);

        if ($line['warehouse_external_id'] !== null) {
            $query->where('warehouse_external_id', $line['warehouse_external_id']);
        }

        return (float) $query->sum('qty');
    }

    /**
     * @param  array{product_external_id:string, warehouse_external_id:string|null, quantity:float}  $line
     * @param  Collection<int, InventoryReservationLine>  $activeReservations
     */
    private function reservedQuantity(Collection $activeReservations, array $line): float
    {
        return (float) $activeReservations
            ->where('product_external_id', $line['product_external_id'])
            ->when(
                $line['warehouse_external_id'] !== null,
                fn ($lines) => $lines->where('warehouse_external_id', $line['warehouse_external_id']),
                fn ($lines) => $lines,
            )
            ->sum(fn (InventoryReservationLine $reservationLine): float => (float) $reservationLine->quantity);
    }

    private function transition(InventoryReservation|int $reservation, string $status, string $timestampColumn): InventoryReservation
    {
        return DB::transaction(function () use ($reservation, $status, $timestampColumn): InventoryReservation {
            $model = $reservation instanceof InventoryReservation
                ? InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id)
                : InventoryReservation::query()->lockForUpdate()->findOrFail($reservation);

            if ($model->status !== InventoryReservation::StatusActive) {
                return $model->refresh();
            }

            $model->forceFill([
                'status' => $status,
                $timestampColumn => now(),
            ])->save();

            return $model->refresh();
        });
    }
}
