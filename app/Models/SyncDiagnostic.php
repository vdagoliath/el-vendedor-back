<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncDiagnostic extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'business_id',
        'user_id',
        'device_id',
        'stage',
        'route_name',
        'status',
        'http_status',
        'error_code',
        'error_message',
        'client_app_version',
        'client_sync_version',
        'server_app_version',
        'server_sync_version',
        'request_meta',
        'response_meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'server_sync_version' => 'integer',
            'request_meta' => 'array',
            'response_meta' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
