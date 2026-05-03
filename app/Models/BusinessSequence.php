<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessSequence extends Model
{
    protected $primaryKey = 'business_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'business_id',
        'last_version',
    ];

    protected function casts(): array
    {
        return [
            'last_version' => 'integer',
        ];
    }
}
