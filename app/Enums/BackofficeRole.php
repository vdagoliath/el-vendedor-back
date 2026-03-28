<?php

namespace App\Enums;

enum BackofficeRole: string
{
    /**
     * Full access to the administrative backoffice.
     */
    case SuperAdmin = 'super_admin';

    /**
     * Technical role focused on business setup and sync readiness.
     */
    case Implementer = 'implementer';

    /**
     * Determine if the role can manage backoffice users.
     */
    public function canManageBackofficeUsers(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Determine if the role can prepare businesses for sync.
     */
    public function canPrepareBusinessesForSync(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Implementer], true);
    }

    /**
     * Determine if the role can access the dashboard overview.
     */
    public function canAccessBackofficeDashboard(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Implementer], true);
    }

    /**
     * Determine if the role can access operational analytics.
     */
    public function canViewBackofficeAnalytics(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Human readable label for UI use.
     */
    public function label(?string $locale = null): string
    {
        $resolvedLocale = strtolower($locale ?? (string) app()->getLocale());

        if (str_starts_with($resolvedLocale, 'en')) {
            return match ($this) {
                self::SuperAdmin => 'Supreme Administrator',
                self::Implementer => 'Implementer',
            };
        }

        return match ($this) {
            self::SuperAdmin => 'Administrador supremo',
            self::Implementer => 'Implementador',
        };
    }
}
