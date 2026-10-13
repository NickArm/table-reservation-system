<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A user's role inside one venue account.
 *
 * Platform super admins are not represented here — that is a flag on the user,
 * because it is not a membership of any one account.
 */
enum TenantRole: string
{
    case Owner = 'owner';
    case Staff = 'staff';

    /**
     * Staff run the floor. They never see money settings or the subscription.
     * SPEC §2.3.
     */
    public function canManageBilling(): bool
    {
        return $this === self::Owner;
    }

    public function canManageSettings(): bool
    {
        return $this === self::Owner;
    }

    public function canRefund(): bool
    {
        return $this === self::Owner;
    }

    public function canManageReservations(): bool
    {
        return true;
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Staff => 'Staff',
        };
    }
}
