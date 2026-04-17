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
        'amount',
        'sub_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'amount' => 'decimal:4',
            'sub_total' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
