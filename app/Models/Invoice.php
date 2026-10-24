<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasServerVersion;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'server_version',
        'contract_version',
        'document_type',
        'series',
        'number',
        'number_padding',
        'display_number',
        'number_source',
        'sale_external_id',
        'original_invoice_external_id',
        'issued_at',
        'status',
        'currency',
        'issuer',
        'customer',
        'totals',
        'payment',
        'hash',
        'reprint_count',
        'footer',
        'voided_at',
        'voided_by',
        'void_reason',
        'created_by',
        'last_received_event_id',
        'source_created_at',
        'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'contract_version' => 'integer',
            'number' => 'integer',
            'number_padding' => 'integer',
            'reprint_count' => 'integer',
            'issuer' => 'array',
            'customer' => 'array',
            'totals' => 'array',
            'payment' => 'array',
            'voided_by' => 'array',
            'created_by' => 'array',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }
}
