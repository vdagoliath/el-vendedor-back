<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'purchase_id',
        'product_external_id',
        'product_title',
        'price',
        'amount',
        'sub_total',
        'unit_of_measure_id',
        'unit_of_measurement',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'amount' => 'decimal:4',
            'sub_total' => 'decimal:4',
            'unit_of_measurement' => 'array',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
