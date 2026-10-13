<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Delivery notices from the mail provider (SPEC §10.3).
 *
 * This exists for one scenario: the guest mistyped their address, the
 * confirmation bounced, and they will arrive expecting a table nobody knows
 * about. The booking is real and the only fix is a phone call — which can only
 * happen if the dashboard knows to ask for one.
 *
 * The notice arrives with a message id and nothing else: no tenant, no
 * booking, no session. Matching it is the whole job.
 */
final class MailWebhookTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private const SECRET = 'a-long-random-string';

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevo.webhook_secret' => self::SECRET]);

        $this->signInAsOwner('Demo Beach Bar');

        $this->reservation = $this->asTenant(function (): Reservation {
            $reservation = Reservation::factory()->create([
                'customer_email' => 'marta@exmaple.test',
            ]);

            $reservation->forceFill([
                'voucher_sent_at' => now(),
                'voucher_delivery' => 'sent',
                'voucher_message_id' => 'abc123@tablereserved.com',
            ])->save();

            return $reservation;
        });

        auth()->logout();
        app(TenantContext::class)->forget();
    }

    #[Test]
    public function a_bounce_is_recorded_against_the_booking(): void
    {
        $this->postJson($this->url(), [
            'event' => 'hard_bounce',
            'email' => 'marta@exmaple.test',
            'message-id' => '<abc123@tablereserved.com>',
            'reason' => 'unknown recipient',
        ])->assertOk()->assertJson(['recorded' => 1]);

        $this->assertSame('bounced', $this->fresh()->voucher_delivery);
    }

    #[Test]
    public function the_venue_is_shown_the_bounce_where_it_will_act_on_it(): void
    {
        $this->postJson($this->url(), [
            'event' => 'hard_bounce',
            'message-id' => 'abc123@tablereserved.com',
        ])->assertOk();

        $this->actingAs($this->owner);

        $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSee('never arrived')
            ->assertSee('worth ringing them', false);
    }

    #[Test]
    public function a_delivery_is_recorded_too(): void
    {
        $this->postJson($this->url(), [
            'event' => 'delivered',
            'message-id' => 'abc123@tablereserved.com',
        ])->assertOk();

        $this->assertSame('delivered', $this->fresh()->voucher_delivery);
    }

    #[Test]
    public function a_batch_of_events_is_accepted(): void
    {
        // Some providers post one event, some post several.
        $this->postJson($this->url(), [
            ['event' => 'request', 'message-id' => 'abc123@tablereserved.com'],
            ['event' => 'delivered', 'message-id' => 'abc123@tablereserved.com'],
        ])->assertOk()->assertJson(['recorded' => 1]);

        $this->assertSame('delivered', $this->fresh()->voucher_delivery);
    }

    #[Test]
    public function being_accepted_for_delivery_is_not_being_delivered(): void
    {
        // Recording "request" as a success would hide the bounce that follows.
        $this->postJson($this->url(), [
            'event' => 'request',
            'message-id' => 'abc123@tablereserved.com',
        ])->assertOk()->assertJson(['recorded' => 0]);

        $this->assertSame('sent', $this->fresh()->voucher_delivery);
    }

    #[Test]
    public function an_unknown_message_is_answered_politely_and_ignored(): void
    {
        // A provider that gets an error retries for days, and there is nothing
        // here worth retrying.
        $this->postJson($this->url(), [
            'event' => 'hard_bounce',
            'message-id' => 'nothing-we-sent@example.com',
        ])->assertOk()->assertJson(['recorded' => 0]);

        $this->assertSame('sent', $this->fresh()->voucher_delivery);
    }

    #[Test]
    public function the_wrong_secret_gets_nowhere(): void
    {
        $this->postJson(route('webhooks.mail', ['secret' => 'guessed']), [
            'event' => 'hard_bounce',
            'message-id' => 'abc123@tablereserved.com',
        ])->assertNotFound();

        $this->assertSame('sent', $this->fresh()->voucher_delivery);
    }

    #[Test]
    public function with_no_secret_configured_the_endpoint_is_shut(): void
    {
        config(['services.brevo.webhook_secret' => null]);

        // Not "anything goes" — nothing goes.
        $this->postJson(route('webhooks.mail', ['secret' => 'anything']), [
            'event' => 'hard_bounce',
            'message-id' => 'abc123@tablereserved.com',
        ])->assertNotFound();
    }

    private function url(): string
    {
        return route('webhooks.mail', ['secret' => self::SECRET]);
    }

    private function fresh(): Reservation
    {
        return $this->asTenant(fn (): Reservation => Reservation::query()->findOrFail($this->reservation->id));
    }
}
