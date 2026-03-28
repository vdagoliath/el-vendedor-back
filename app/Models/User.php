<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BackofficeRole;
use App\Enums\BusinessRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'locale',
        'backoffice_role',
        'is_platform_admin',
        'current_business_id',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'backoffice_role' => BackofficeRole::class,
            'email_verified_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get the business currently selected by the user.
     */
    public function currentBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'current_business_id');
    }

    /**
     * Get the businesses that the user belongs to.
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * Determine if the user is a platform administrator.
     */
    public function isPlatformAdministrator(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /**
     * Determine the effective backoffice role for the user.
     */
    public function getEffectiveBackofficeRole(): ?BackofficeRole
    {
        if ($this->backoffice_role instanceof BackofficeRole) {
            return $this->backoffice_role;
        }

        if (is_string($this->backoffice_role)) {
            return BackofficeRole::tryFrom($this->backoffice_role);
        }

        if ($this->isPlatformAdministrator()) {
            return BackofficeRole::SuperAdmin;
        }

        return null;
    }

    /**
     * Determine if the user has any backoffice role.
     */
    public function hasBackofficeRole(): bool
    {
        return $this->getEffectiveBackofficeRole() !== null;
    }

    /**
     * Determine if the user is a backoffice super admin.
     */
    public function isBackofficeSuperAdmin(): bool
    {
        return $this->getEffectiveBackofficeRole() === BackofficeRole::SuperAdmin;
    }

    /**
     * Determine if the user is a backoffice implementer.
     */
    public function isBackofficeImplementer(): bool
    {
        return $this->getEffectiveBackofficeRole() === BackofficeRole::Implementer;
    }

    /**
     * Determine if the user can access the backoffice.
     */
    public function canAccessBackoffice(): bool
    {
        return $this->hasBackofficeRole();
    }

    /**
     * Determine if the user can manage backoffice users.
     */
    public function canManageBackofficeUsers(): bool
    {
        return $this->getEffectiveBackofficeRole()?->canManageBackofficeUsers() ?? false;
    }

    /**
     * Determine if the user can prepare businesses for sync.
     */
    public function canPrepareBusinessesForSync(): bool
    {
        return $this->getEffectiveBackofficeRole()?->canPrepareBusinessesForSync() ?? false;
    }

    /**
     * Determine if the user can access the backoffice dashboard.
     */
    public function canAccessBackofficeDashboard(): bool
    {
        return $this->getEffectiveBackofficeRole()?->canAccessBackofficeDashboard() ?? false;
    }

    /**
     * Determine if the user can view backoffice analytics.
     */
    public function canViewBackofficeAnalytics(): bool
    {
        return $this->getEffectiveBackofficeRole()?->canViewBackofficeAnalytics() ?? false;
    }

    /**
     * Get the active businesses available to the user.
     */
    public function activeBusinesses(): BelongsToMany
    {
        return $this->businesses()
            ->wherePivot('is_active', true)
            ->where('businesses.is_active', true);
    }

    /**
     * Determine if the user belongs to the given business.
     */
    public function belongsToBusiness(Business $business): bool
    {
        return $this->businesses()
            ->whereKey($business->getKey())
            ->exists();
    }

    /**
     * Determine if the user has the given role in the business.
     */
    public function hasBusinessRole(Business $business, BusinessRole $role): bool
    {
        return $this->activeBusinesses()
            ->whereKey($business->getKey())
            ->wherePivot('role', $role->value)
            ->exists();
    }

    /**
     * Determine if the user can access the given business.
     */
    public function canAccessBusiness(Business $business): bool
    {
        if (! $business->is_active) {
            return false;
        }

        if ($this->hasBackofficeRole()) {
            return true;
        }

        return $this->isPlatformAdministrator()
            || $this->activeBusinesses()->whereKey($business->getKey())->exists();
    }

    /**
     * Determine if the user owns the given business.
     */
    public function ownsBusiness(Business $business): bool
    {
        return $this->hasBusinessRole($business, BusinessRole::Owner);
    }

    /**
     * Determine if the user is an employee of the given business.
     */
    public function isEmployeeOf(Business $business): bool
    {
        return $this->hasBusinessRole($business, BusinessRole::Employee);
    }

    /**
     * Determine if the user can manage the given business.
     */
    public function canManageBusiness(Business $business): bool
    {
        return $this->isPlatformAdministrator() || $this->ownsBusiness($business);
    }

    /**
     * Determine if the user can sell in the given business.
     */
    public function canSellInBusiness(Business $business): bool
    {
        return $this->isPlatformAdministrator()
            || $this->ownsBusiness($business)
            || $this->isEmployeeOf($business);
    }

    /**
     * Determine if the user can manage contacts of the given type.
     */
    public function canManageContactTypeInBusiness(Business $business, ?string $contactType): bool
    {
        if ($contactType === 'supplier') {
            return $this->canManageBusiness($business);
        }

        if ($contactType === 'customer') {
            return $this->canSellInBusiness($business);
        }

        return $this->canManageBusiness($business);
    }

    /**
     * Determine if the user can prepare the given business for sync.
     */
    public function canPrepareBusinessForSync(Business $business): bool
    {
        return $this->canPrepareBusinessesForSync() && $this->canAccessBusiness($business);
    }

    /**
     * Persist the dedicated backoffice role and keep the legacy admin flag in sync.
     */
    public function setBackofficeRole(?BackofficeRole $role): void
    {
        $this->forceFill([
            'backoffice_role' => $role?->value,
            'is_platform_admin' => $role === BackofficeRole::SuperAdmin,
        ])->save();
    }

    /**
     * Resolve and persist the current business when possible.
     */
    public function ensureCurrentBusiness(): ?Business
    {
        $this->loadMissing('currentBusiness');

        if ($this->currentBusiness && $this->canAccessBusiness($this->currentBusiness)) {
            return $this->currentBusiness;
        }

        if ($this->current_business_id !== null) {
            $this->forceFill(['current_business_id' => null])->save();
            $this->unsetRelation('currentBusiness');
        }

        if ($this->hasBackofficeRole()) {
            $business = Business::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->first();

            if (! $business) {
                return null;
            }

            $this->forceFill(['current_business_id' => $business->getKey()])->save();
            $this->setRelation('currentBusiness', $business);

            return $business;
        }

        $business = $this->activeBusinesses()->first();

        if (! $business) {
            return null;
        }

        $this->forceFill(['current_business_id' => $business->getKey()])->save();
        $this->setRelation('currentBusiness', $business);

        return $business;
    }

    /**
     * Set the user's current business.
     */
    public function switchCurrentBusiness(Business $business): void
    {
        $this->forceFill([
            'current_business_id' => $business->getKey(),
        ])->save();

        $this->setRelation('currentBusiness', $business);
    }
}
