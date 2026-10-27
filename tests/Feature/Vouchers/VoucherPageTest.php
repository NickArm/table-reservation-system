<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers;

use App\Models\Reservation;
use App\Tenancy\TenantContext;
use App\Vouchers\VoucherPdf;
use App\Vouchers\VoucherToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The page the QR opens (SPEC §11.2).
 *
 * The address carries a booking code and a signature and nothing else — no
 * tenant, no session, no identifier — because it has to survive being printed
 * in June and scanned in September on a phone that has never seen the site.
 */
final class VoucherPageTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Reservation $reservation;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');

        $this->reservation = $this->asTenant(
            fn (): Reservation => Reservation::factory()->create([
                'customer_name' => 'Marta Olsen',
                'party_size' => 2,
            ])
        );

        $this->token = VoucherToken::for($this->reservation);

        auth()->logout();
        app(TenantContext::class)->forget();
    }

    #[Test]
    public function the_signed_address_shows_the_booking(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee($this->reservation->code)
            ->assertSee('Marta Olsen')
            ->assertSee('not a tax document', false)
            // The QR on the page points back at the page, so a guest can show
            // a screenshot of it to somebody else's scanner.
            ->assertSee('<svg', false);
    }

    #[Test]
    public function the_page_resolves_its_own_tenant(): void
    {
        // Nothing is bound before the request: the address has no slug in it,
        // so the controller has to find the tenant from the code itself. If it
        // did not, every tenant-scoped query on the page would refuse.
        $this->assertFalse(app(TenantContext::class)->has());

        $this->get($this->url())->assertOk();
    }

    #[Test]
    public function a_wrong_signature_is_indistinguishable_from_a_wrong_code(): void
    {
        $tampered = $this->get(route('voucher', [
            'code' => $this->reservation->code,
            'token' => str_repeat('0', 16),
        ]));

        $unknown = $this->get(route('voucher', [
            'code' => 'ZZZZZZ',
            'token' => $this->token,
        ]));

        // Both 404. Anything else turns this page into a way of finding out
        // whether a booking code is real.
        $tampered->assertNotFound();
        $unknown->assertNotFound();
    }

    #[Test]
    public function the_calendar_file_downloads(): void
    {
        $response = $this->get(route('voucher.ics', [
            'code' => $this->reservation->code,
            'token' => $this->token,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('text/calendar', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('BEGIN:VEVENT', (string) $response->getContent());
    }

    #[Test]
    public function the_pdf_downloads_or_sends_them_to_the_page_instead(): void
    {
        $response = $this->get(route('voucher.pdf', [
            'code' => $this->reservation->code,
            'token' => $this->token,
        ]));

        /*
         * Two valid outcomes, because the PDF library is optional by design:
         * without dompdf installed the page itself is a perfectly good
         * voucher, and a guest tapping "download" should land on it rather
         * than on an error (SPEC §11.2).
         */
        if (! VoucherPdf::available()) {
            $response->assertRedirect($this->url());

            return;
        }

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_pdf_is_behind_the_same_signature_as_the_page(): void
    {
        $this->get(route('voucher.pdf', [
            'code' => $this->reservation->code,
            'token' => $this->token.'x',
        ]))->assertNotFound();

        // A real code with somebody else's signature is the case worth having
        // a test for: the code travels in an email, the signature does not.
        $this->get(route('voucher.pdf', [
            'code' => 'NOSUCHCODE',
            'token' => $this->token,
        ]))->assertNotFound();
    }

    #[Test]
    public function a_cancelled_booking_says_so_rather_than_disappearing(): void
    {
        $this->asTenant(fn () => $this->reservation->forceFill([
            'status' => \App\Enums\ReservationStatus::CancelledByVenue,
            'cancelled_at' => now(),
            'cancellation_reason' => 'The beach closed for weather.',
        ])->save());

        // The guest who scans an old voucher deserves to be told, not to be
        // shown a page that looks valid.
        $this->get($this->url())
            ->assertOk()
            ->assertSee('cancelled')
            ->assertSee('The beach closed for weather.');
    }

    #[Test]
    public function staff_of_this_venue_can_check_a_guest_in_from_the_voucher(): void
    {
        // The cheap version of a door scanner: no app, no new screen, just the
        // voucher page opened by somebody who is signed in (SPEC §32.4).
        $this->actingAs($this->owner);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Check in');
    }

    #[Test]
    public function a_guest_sees_no_check_in_control(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('Check in');
    }

    private function url(): string
    {
        return route('voucher', ['code' => $this->reservation->code, 'token' => $this->token]);
    }
}
