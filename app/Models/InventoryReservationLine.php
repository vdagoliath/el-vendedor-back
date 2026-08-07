<?php

namespace App\Models;

use Database\Factories\InventoryReservationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReservationLine extends Model
{
    /** @use HasFactory<InventoryReservationLineFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_reservation_id',
        'business_id',
        'product_external_id',
        'warehouse_external_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
