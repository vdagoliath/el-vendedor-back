<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'business_id',
        'employee_external_id',
        'device_uuid',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
