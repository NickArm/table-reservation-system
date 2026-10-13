<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers;

use App\Models\Reservation;
use App\Support\QrCode;
use App\Vouchers\VoucherToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The signature in the QR (SPEC §32.3, hook 1).
 *
 * The cost of getting this wrong is not a security hole today — it is that
 * every voucher already printed or emailed carries the old format, and the day
 * a door scanner arrives they all have to be reissued. Which is exactly why
 * the hook is mandatory in Phase 1 and tested now rather than then.
 */
final class VoucherTokenTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');

        $this->reservation = $this->asTenant(fn (): Reservation => Reservation::factory()->create());
    }

    #[Test]
    public function the_qr_carries_no_identifier(): void
    {
        $url = VoucherToken::url($this->reservation);

        $this->assertStringNotContainsString((string) $this->reservation->uuid, $url);
        $this->assertStringNotContainsString('/'.$this->reservation->id.'/', $url);
        $this->assertStringContainsString($this->reservation->code, $url);
    }

    #[Test]
    public function the_signature_is_stable_and_specific(): void
    {
        $other = $this->asTenant(fn (): Reservation => Reservation::factory()->create());

        $token = VoucherToken::for($this->reservation);

        $this->assertSame($token, VoucherToken::for($this->reservation));
        $this->assertNotSame($token, VoucherToken::for($other));

        $this->assertTrue(VoucherToken::matches($this->reservation, $token));
        $this->assertFalse(VoucherToken::matches($other, $token));
        $this->assertFalse(VoucherToken::matches($this->reservation, strrev($token)));
    }

    #[Test]
    public function the_address_stays_short_enough_to_scan_in_poor_light(): void
    {
        $url = VoucherToken::url($this->reservation);

        // Fewer characters mean fewer modules, and fewer modules mean a symbol
        // that reads at arm's length in the dark. A version 4 symbol is 33
        // across; anything much past that starts needing a steady hand.
        $this->assertLessThanOrEqual(62, strlen($url), 'The voucher URL has grown past a version 4 symbol.');
        $this->assertLessThanOrEqual(33, count(QrCode::matrix($url)));
    }
}
