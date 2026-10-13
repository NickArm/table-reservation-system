<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Reservation;
use App\Models\Venue;
use App\Notifications\BookingArrived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The alerts screen and the bell (SPEC §10.2).
 *
 * Every setting here has a working default, so the test that matters most is
 * the one proving a venue which never opens this page is still told about its
 * bookings.
 */
final class NotificationSettingsTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function a_new_account_is_already_set_up_sensibly(): void
    {
        $venue = $this->freshVenue();

        $this->assertNotNull($venue);
        $this->assertTrue($venue->notify_on_booking, 'A new venue would hear nothing about its bookings.');
        $this->assertSame(8, $venue->daily_summary_hour);
        $this->assertSame(24, $venue->reminder_hours_before);
        $this->assertContains($this->owner->email, $venue->notificationRecipients());
    }

    #[Test]
    public function the_screen_shows_who_is_currently_told(): void
    {
        $this->get(route('dashboard.notifications.edit'))
            ->assertOk()
            ->assertSee('Alerts')
            ->assertSee($this->owner->email)
            ->assertSee('Quiet hours');
    }

    #[Test]
    public function the_settings_save(): void
    {
        $this->patch(route('dashboard.notifications.update'), [
            'notify_on_booking' => '1',
            'notification_emails' => "Kitchen@Demo.test\nmanager@demo.test",
            'daily_summary' => '1',
            'daily_summary_hour' => '7',
            'quiet_hours_from' => '01:00',
            'quiet_hours_to' => '08:30',
            'reminders' => '1',
            'reminder_hours_before' => '4',
        ])->assertRedirect();

        $venue = $this->freshVenue();

        $this->assertNotNull($venue);
        $this->assertSame(['kitchen@demo.test', 'manager@demo.test'], $venue->notification_emails);
        $this->assertSame(7, $venue->daily_summary_hour);
        $this->assertSame(4, $venue->reminder_hours_before);
        $this->assertStringStartsWith('01:00', (string) $venue->quiet_hours_from);
    }

    #[Test]
    public function unticking_a_box_switches_the_thing_off_rather_than_leaving_it(): void
    {
        $this->patch(route('dashboard.notifications.update'), [
            'notification_emails' => '',
        ])->assertRedirect();

        $venue = $this->freshVenue();

        $this->assertNotNull($venue);
        $this->assertFalse($venue->notify_on_booking);
        $this->assertNull($venue->daily_summary_hour);
        $this->assertNull($venue->reminder_hours_before);
    }

    #[Test]
    public function rubbish_among_the_addresses_is_dropped_rather_than_refused(): void
    {
        // A stray comma should not stop the rest of the form from saving.
        $this->patch(route('dashboard.notifications.update'), [
            'notification_emails' => 'kitchen@demo.test, , not-an-address, manager@demo.test',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            ['kitchen@demo.test', 'manager@demo.test'],
            $this->freshVenue()?->notification_emails
        );
    }

    #[Test]
    public function quiet_hours_need_both_ends(): void
    {
        $this->patch(route('dashboard.notifications.update'), [
            'quiet_hours_from' => '01:00',
        ])->assertSessionHasErrors('quiet_hours_to');
    }

    /**
     * The venue is tenant-scoped, so reading it back outside a request needs a
     * tenant bound — which is the point of the scope rather than a nuisance.
     */
    private function freshVenue(): ?Venue
    {
        return $this->asTenant(fn (): ?Venue => Venue::query()->find($this->venue->id));
    }

    #[Test]
    public function the_bell_shows_what_arrived_and_clears_in_one_tap(): void
    {
        $reservation = $this->asTenant(fn (): Reservation => Reservation::factory()->create([
            'customer_name' => 'Marta Olsen',
            'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
        ]));

        $this->owner->notify(new BookingArrived($reservation, 'Europe/Athens'));

        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('Marta Olsen');

        $this->post(route('dashboard.notifications.read'))->assertRedirect();

        $this->assertCount(0, $this->owner->fresh()?->unreadNotifications ?? collect());
    }
}
