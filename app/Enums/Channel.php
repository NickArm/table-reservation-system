<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a reservation came from (SPEC §9.5).
 *
 * This is the number that makes an owner renew: "the widget on my site
 * brought 40% of last month's bookings".
 */
enum Channel: string
{
    case Website = 'website';
    case Widget = 'widget';
    case Direct = 'direct';
    case Qr = 'qr';
    case Google = 'google';
    case Api = 'api';
    case Phone = 'phone';
    case Walkin = 'walkin';
    case Social = 'social';

    /** Taken by a person at the venue rather than by a guest online. */
    public function isTakenByStaff(): bool
    {
        return match ($this) {
            self::Phone, self::Walkin => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Booking page',
            self::Widget => 'Website widget',
            self::Direct => 'Direct link',
            self::Qr => 'QR code',
            self::Google => 'Google',
            self::Api => 'Their own form',
            self::Phone => 'Phone',
            self::Walkin => 'Walk-in',
            self::Social => 'Social media',
        };
    }
}
