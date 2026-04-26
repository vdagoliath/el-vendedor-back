<?php

namespace App\Models;

use App\Enums\BusinessRole;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'address',
        'phone',
        'default_currency',
        'policies',
        'license_expires_at',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
}
