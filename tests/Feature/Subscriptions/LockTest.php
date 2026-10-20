<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Enums\TenantStatus;
use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * What "locked" means (SPEC §4.4).
 *
 * The whole design here is that the person being locked out is the person we
 * want money from. So: reading always works, the billing page always works,
 * nothing is deleted, and the guests who already booked are not punished for
 * a dispute between the venue and us.
 */
final class LockTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(function (): void {
            $type = $this->sunbedType(['name' => 'Sunbeds']);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 3);
        });
    }

    #[Test]
    public function reading_the_dashboard_still_works(): void
    {
        $this->expire();

        // Not a hard 403: they have to be able to see where they stand and do
        // something about it.
        $this->get(route('dashboard.today'))->assertOk();
        $this->get(route('dashboard.reservations.index'))->assertOk();
        $this->get(route('dashboard.guests.index'))->assertOk();
        $this->get(route('dashboard.booking-types.index'))->assertOk();
    }

    #[Test]
    public function the_export_never_stops_working(): void
    {
        $this->expire();

        // Promised explicitly in the spec. Somebody who has stopped paying
        // still owns their data.
        $this->get(route('dashboard.reservations.export'))->assertOk();
        $this->get(route('dashboard.guests.export'))->assertOk();
    }

    #[Test]
    public function writing_is_refused_with_a_sentence_rather_than_an_error(): void
    {
        $this->expire();

        $response = $this->post(route('dashboard.booking-types.store'), [
            'name' => 'Dinner',
            'resource_type' => 'table',
            'booking_mode' => 'slot',
            'payment_requirement' => 'none',
            'min_lead_time_minutes' => 60,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame(1, $this->asTenant(
            static fn (): int => \App\Models\BookingType::query()->count()
        ));
    }

    #[Test]
    public function the_billing_page_is_reachable_when_everything_else_is_locked(): void
    {
        $this->expire();

        // Locking somebody out of the page where they would pay is the one
        // mistake that guarantees they never do.
        $this->get(route('dashboard.billing'))
            ->assertOk()
            ->assertSee('run out');
    }

    #[Test]
    public function the_public_page_says_one_calm_sentence(): void
    {
        $this->expire();

        auth()->logout();
        app(TenantContext::class)->forget();

        $this->get(route('public.search', [$this->tenant, 'type' => 'sunbeds']))
            ->assertStatus(503)
            ->assertSee('temporarily unavailable')
            ->assertDontSee('subscription');
    }

    #[Test]
    public function the_widget_says_the_same_thing_in_its_own_shape(): void
    {
        $this->expire();

        auth()->logout();
        app(TenantContext::class)->forget();

        // Not an empty box and not a console error: the widget is on somebody
        // else's homepage.
        $this->getJson("/api/internal/{$this->tenant->slug}/times?type=sunbeds&date=2026-07-04")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'bookings_closed')
            ->assertJsonPath('error.message', 'Online reservations are temporarily unavailable.');
    }

    #[Test]
    public function a_booking_already_made_stays_visible_and_its_voucher_still_works(): void
    {
        $reservation = $this->asTenant(static fn (): Reservation => Reservation::factory()->create([
            'customer_email' => 'anna@example.test',
        ]));

        $this->expire();

        $this->get(route('dashboard.reservations.show', $reservation))->assertOk();

        auth()->logout();
        app(TenantContext::class)->forget();

        // The guest paid in good faith. Whatever is wrong is between the
        // venue and us.
        $this->get(route('public.confirmed', [$this->tenant, $reservation]))->assertOk();
    }

    #[Test]
    public function a_venue_in_grace_is_not_locked_at_all(): void
    {
        $this->tenant->forceFill(['status' => TenantStatus::Grace])->save();

        $this->post(route('dashboard.booking-types.store'), [
            'name' => 'Dinner',
            'resource_type' => 'table',
            'booking_mode' => 'slot',
            'payment_requirement' => 'none',
            'min_lead_time_minutes' => 60,
        ])->assertSessionMissing('error');

        auth()->logout();
        app(TenantContext::class)->forget();

        $this->get(route('public.search', [$this->tenant, 'type' => 'sunbeds']))->assertOk();
    }

    private function expire(): void
    {
        $this->tenant->forceFill(['status' => TenantStatus::Expired])->save();
    }
}
