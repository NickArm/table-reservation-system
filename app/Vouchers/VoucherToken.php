<?php

declare(strict_types=1);

namespace App\Vouchers;

use App\Models\Reservation;
use Illuminate\Support\Facades\Config;

/**
 * The signature that turns a booking code into something only we could have
 * issued (SPEC §32.3, hook 1).
 *
 * The QR on a voucher never carries a raw identifier. If it did, anyone could
 * count upwards and read other people's bookings — and the day the door
 * scanner arrives, every voucher already printed or emailed would have to be
 * reissued in a new format. A signature costs nothing today and settles both.
 *
 * The signed URL is deliberately short: fewer characters make a smaller
 * symbol, and a smaller symbol scans faster in the half-light of a beach bar
 * at ten in the evening.
 */
final class VoucherToken
{
    /**
     * Sixty-four bits of signature. Guessing one would take longer than the
     * season, and the route is rate limited besides.
     */
    private const LENGTH = 16;

    public static function for(Reservation $reservation): string
    {
        return substr(
            hash_hmac('sha256', 'voucher:'.$reservation->uuid, self::key()),
            0,
            self::LENGTH
        );
    }

    public static function matches(Reservation $reservation, string $token): bool
    {
        return hash_equals(self::for($reservation), $token);
    }

    public static function url(Reservation $reservation): string
    {
        return route('voucher', [
            'code' => $reservation->code,
            'token' => self::for($reservation),
        ]);
    }

    private static function key(): string
    {
        $key = (string) Config::string('app.key');

        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }
}
