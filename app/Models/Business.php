<?php

namespace App\Models;

use App\Enums\BackofficeRole;
use App\Enums\BusinessRole;
use App\Models\Concerns\HasServerVersion;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    use HasServerVersion;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'server_version',
        'address',
        'country',
        'province',
        'municipality',
        'street',
        'phone',
        'default_currency',
        'policies',
        'data_reset_version',
        'data_reset_at',
        'license_expires_at',
        'is_active',
        'created_by_user_id',
    ];

    /**
     * El propio id del negocio funciona como `business_id` para la
     * asignación de `server_version` (este modelo es el "perfil del
     * negocio" desde la óptica del cliente).
     */
    public function serverVersionBusinessId(): ?int
    {
        return $this->id ? (int) $this->id : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'data_reset_at' => 'datetime',
            'data_reset_version' => 'integer',
            'license_expires_at' => 'datetime',
            'policies' => 'array',
        ];
    }

    /**
     * Get the users that belong to the business.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * Get the owners that manage the business.
     */
    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', BusinessRole::Owner->value);
    }

    /**
     * Get the employees assigned to the business.
     */
    public function employees(): BelongsToMany
    {
        return $this->users()->wherePivot('role', BusinessRole::Employee->value);
    }

    /**
     * Get the backoffice user that created this business.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Limit the query to businesses visible to the given user according to
     * their backoffice role. Implementers only see businesses they created;
     * super admins and platform admins see everything.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isPlatformAdministrator() || $user->getEffectiveBackofficeRole() === BackofficeRole::SuperAdmin) {
            return $query;
        }

        if ($user->getEffectiveBackofficeRole() === BackofficeRole::Implementer) {
            return $query->where('created_by_user_id', $user->getKey());
        }

        return $query->whereRaw('1 = 0');
    }
}
