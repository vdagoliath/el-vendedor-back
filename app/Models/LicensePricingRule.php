<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicensePricingRule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'currency',
        'monthly_price',
        'min_active_pos',
        'max_active_pos',
        'min_active_owners',
        'max_active_owners',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'min_active_pos' => 'integer',
            'max_active_pos' => 'integer',
            'min_active_owners' => 'integer',
            'max_active_owners' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
