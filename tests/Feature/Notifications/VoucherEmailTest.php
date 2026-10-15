<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\ReservationWasConfirmed;
use App\Mail\BookingVoucher;
use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * The guest's confirmation (SPEC §11).
 *
 * Two things are being protected here. One voucher per booking, however many
 * times the payment is settled — the webhook, the return page and the hourly
 * reconciliation all arrive at the same place and only one email should come
 * out. And the queued listener has to bind its own tenant, because a job
 * starts with nothing bound and every scoped query would otherwise refuse.
 */
final class VoucherEmailTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    #[Test]
    public function confirming_a_booking_sends_the_voucher(): void
    {
        Mail::fake();

        $reservation = $this->booking();

        $this->fire($reservation);

        Mail::assertSent(BookingVoucher::class, function (BookingVoucher $mail) use ($reservation): bool {
            return $mail->hasTo((string) $reservation->customer_email)
                && $mail->voucher->reservation->is($reservation);
        });

        $fresh = $this->reload($reservation);

        $this->assertNotNull($fresh?->voucher_sent_at);
        $this->assertSame('sent', $fresh?->voucher_delivery);
    }

    #[Test]
    public function the_voucher_is_sent_once_however_often_the_payment_settles(): void
    {
        Mail::fake();

        $reservation = $this->booking();

        // The webhook, then the return page, then reconciliation.
        $this->fire($reservation);
        $this->fire($this->reload($reservation) ?? $reservation);
        $this->fire($this->reload($reservation) ?? $reservation);

        // Counted by mailable, not in total: the same event also alerts the
        // venue, and that alert is supposed to go out. What must not happen
        // twice is the guest's voucher.
        Mail::assertSent(BookingVoucher::class, 1);
    }

    #[Test]
    public function the_message_carries_the_booking_and_its_attachments(): void
    {
        $reservation = $this->booking();

        $this->fire($reservation);

        // The array transport keeps the message as it was actually built, so
        // this checks the rendered email rather than our intentions about it.
        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        // The same event also alerts the venue, so the guest's message is
        // picked by its recipient rather than by being the only one in the
        // transport.
        $email = null;

        foreach ($messages as $sent) {
            $message = $sent->getOriginalMessage();

            if (! $message instanceof Email) {
                continue;
            }

            foreach ($message->getTo() as $to) {
                if ($to->getAddress() === (string) $reservation->customer_email) {
                    $email = $message;
                }
            }
        }

        $this->assertNotNull($email, 'The guest was not sent a voucher.');

        // From is ours, the display name is the venue's, and a reply reaches
        // the venue rather than us (SPEC §10.3).
        $this->assertSame(config('mail.from.address'), $email->getFrom()[0]->getAddress());
        $this->assertSame('Demo Beach Bar', $email->getFrom()[0]->getName());

        // The header a bounce notice is traced back by (SPEC §10.3).
        $this->assertSame(
            (string) $reservation->uuid,
            $email->getHeaders()->get('X-Reservation')?->getBodyAsString()
        );

        $body = (string) $email->getHtmlBody();

        $this->assertStringContainsString($reservation->code, $body);
        $this->assertStringContainsString('not a tax document', $body);
        $this->assertStringContainsString('Deducted from your bill', $body);

        // The QR travels as an inline image: no email client renders an SVG,
        // and most strip data URIs.
        $this->assertStringContainsString('cid:', $body);

        $names = array_map(
            static fn (object $part): string => (string) $part->getFilename(),
            $email->getAttachments()
        );

        $this->assertNotEmpty(
            array_filter($names, static fn (string $name): bool => str_ends_with($name, '.ics')),
            'The calendar file is not attached.'
        );
    }

    #[Test]
    public function the_message_id_is_recorded_so_a_bounce_can_be_traced(): void
    {
        $reservation = $this->booking();

        $this->fire($reservation);

        $this->assertNotNull(
            $this->reload($reservation)?->voucher_message_id,
            'Without the message id, a bounce notice has nothing to match on.'
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(array $attributes = []): Reservation
    {
        $this->signInAsOwner('Demo Beach Bar');

        $reservation = $this->asTenant(function () use ($attributes): Reservation {
            $type = $this->sunbedType(['name' => 'Sunbeds', 'deposit_label' => 'Deducted from your bill']);
            $area = $this->areaIn($type, 'Front row');
            $resources = $this->resourcesIn($area, 'A', 1, ['base_price_cents' => 3000]);

            return Reservation::factory()
                ->forResource($resources->first())
                ->create([
                    'customer_name' => 'Marta Olsen',
                    'customer_email' => 'marta@example.test',
                    'deposit_label_snapshot' => 'Deducted from your bill',
                    'resource_snapshot' => [
                        'label' => 'A1',
                        'area' => 'Front row',
                        'components' => null,
                    ],
                    ...$attributes,
                ]);
        });

        auth()->logout();
        app(TenantContext::class)->forget();

        return $reservation;
    }

    /**
     * Reading a booking back needs a tenant bound, because nothing here leaves
     * one in context — which is the scope working, not getting in the way.
     */
    private function reload(Reservation $reservation): ?Reservation
    {
        return $this->asTenant(fn (): ?Reservation => Reservation::query()->find($reservation->getKey()));
    }

    /**
     * Raise the event the way the actions do, from outside any tenant context,
     * so the listener has to bind its own.
     */
    private function fire(Reservation $reservation): void
    {
        app(TenantContext::class)->forget();

        event(new ReservationWasConfirmed(
            tenantId: (int) $reservation->tenant_id,
            reservationId: (int) $reservation->getKey(),
            uuid: (string) $reservation->uuid,
        ));
    }
}
