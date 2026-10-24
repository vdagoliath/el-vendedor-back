<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sale_id',
        'product_external_id',
        'product_title',
        'price',
        'price_base',
        'amount',
        'sub_total',
        'sub_total_base',
        'currency',
        'exchange_rate_from_base',
        'unit_of_measure_id',
        'unit_of_measurement',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'price_base' => 'decimal:4',
            'amount' => 'decimal:4',
            'sub_total' => 'decimal:4',
            'sub_total_base' => 'decimal:4',
            'exchange_rate_from_base' => 'decimal:6',
            'unit_of_measurement' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
