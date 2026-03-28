<?php

namespace App\Enums;

enum BusinessRole: string
{
    case Owner = 'owner';
    case Employee = 'employee';

    /**
     * Determine if the role can manage the full business.
     */
    public function canManageBusiness(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Determine if the role can operate the point of sale.
     */
    public function canSell(): bool
    {
        return in_array($this, [self::Owner, self::Employee], true);
    }
}
