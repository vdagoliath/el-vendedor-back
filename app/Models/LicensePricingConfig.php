<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicensePricingConfig extends Model
{
    protected $fillable = [
        'active_pos_operation_window_days',
    ];

    protected function casts(): array
    {
        return [
            'active_pos_operation_window_days' => 'integer',
        ];
    }
}
