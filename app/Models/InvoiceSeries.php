<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSeries extends Model
{
    protected $fillable = [
        'business_id',
        'series',
        'next_number',
        'padding',
        'last_number',
        'last_invoice_external_id',
        'last_issued_at',
        'gaps',
    ];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
            'last_number' => 'integer',
            'last_issued_at' => 'datetime',
            'gaps' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
