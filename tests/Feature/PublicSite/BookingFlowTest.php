<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Enums\ReservationStatus;
use App\Models\BookingType;
use App\Models\PriceRule;
use App\Models\Reservation;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * A tourist on a beach, booking a sunbed (SPEC §8).
 *
 * Three screens and no account: search, pick, done. If this test ever needs a
 * fourth request to reach a confirmed booking, the flow has gone wrong (§1.1).
 */
final class BookingFlowTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    private Tenant $venueTenant;

    private BookingType $bookingType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
        $this->venueTenant = $this->tenant;

        $this->bookingType = $this->asTenant(function (): BookingType {
            $type = $this->sunbedType(['name' => 'Sunbeds']);

            $front = $this->areaIn($type, 'Front row', ['description' => 'Feet in the sand.']);
            $this->resourcesIn($front, 'A', 3, ['base_price_cents' => 3000]);

            $back = $this->areaIn($type, 'Back row');
            $this->resourcesIn($back, 'B', 2, ['base_price_cents' => 1600]);

            return $type;
        });

        // The guest is not the owner.
        auth()->logout();
        app(\App\Tenancy\TenantContext::class)->forget();
    }

    #[Test]
    public function a_guest_books_a_sunbed_in_three_screens(): void
    {
        // 1. The landing page offers a search.
        $this->get(route('public.booking', $this->venueTenant))
            ->assertOk()
            ->assertSee('See what is free');

        // 2. The results show times and spots, with prices.
        $results = $this->get(route('public.search', [
            $this->venueTenant,
            'type' => 'sunbeds',
            'people' => 2,
            'date' => self::DATE,
        ]));

        $results->assertOk()
            ->assertSee('Front row')
            ->assertSee('Back row')
            ->assertSee('10:00')
            ->assertSee('€30')
            ->assertSee('€16');

        // 3. Taking a spot holds it and asks who they are.
        $spot = $this->spot('A1');

        $hold = $this->post(route('public.hold', $this->venueTenant), [
            'resource' => $spot->id,
            'starts_at' => $this->athens('11:00')->toIso8601String(),
            'people' => 2,
        ]);

        $hold->assertRedirect();

        $held = $this->asVenue(static fn (): ReservationHold => ReservationHold::query()->firstOrFail());

        $this->assertSame($spot->id, $held->resource_id);
        $this->assertSame(2, $held->party_size);

        $this->get(route('public.book', [$this->venueTenant, $held]))
            ->assertOk()
            ->assertSee('A1')
            ->assertSee('Feet in the sand.')
            ->assertSee('€30');

        // 4. Confirming creates the booking and frees the hold.
        $this->post(route('public.book.store', [$this->venueTenant, $held]), [
            'name' => 'Anna Müller',
            'email' => 'Anna.Muller@example.com',
            'calling_code' => '+49',
            'phone' => '0151 23456789',
            'notes' => 'Please keep us in the shade.',
            'terms' => '1',
        ])->assertRedirect();

        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame('Anna Müller', $reservation->customer_name);
        $this->assertSame('anna.muller@example.com', $reservation->customer_email);
        $this->assertSame('+4915123456789', $reservation->customer_phone);
        $this->assertSame('Please keep us in the shade.', $reservation->notes);
        $this->assertSame(3000, $reservation->amount_cents);
        $this->assertNotNull($reservation->terms_accepted_at);
        $this->assertFalse($reservation->marketing_consent);

        $this->assertSame(0, $this->asVenue(static fn (): int => ReservationHold::query()->count()));

        // And the voucher says everything the guest needs.
        $this->get(route('public.confirmed', [$this->venueTenant, $reservation]))
            ->assertOk()
            ->assertSee($reservation->code)
            ->assertSee('Anna Müller')
            ->assertSee('To change or cancel');

        // Three screens, and the confirmation is already on its way — raised
        // by the booking itself rather than by the page the guest landed on
        // (SPEC §11).
        $this->assertNotNull($reservation->fresh()?->voucher_sent_at);
    }

    #[Test]
    public function the_spot_is_off_the_board_while_somebody_fills_in_their_name(): void
    {
        $spot = $this->spot('A1');

        $this->post(route('public.hold', $this->venueTenant), [
            'resource' => $spot->id,
            'starts_at' => $this->athens('11:00')->toIso8601String(),
            'people' => 2,
        ])->assertRedirect();

        // A second browser, same beach, same morning.
        $this->flushSession();

        $this->get(route('public.search', [
            $this->venueTenant,
            'type' => 'sunbeds',
            'people' => 2,
            'date' => self::DATE,
            'time' => '11:00',
        ]))->assertOk()->assertDontSee('>A1<', false);
    }

    #[Test]
    public function changing_your_mind_gives_the_first_spot_back(): void
    {
        $first = $this->spot('A1');
        $second = $this->spot('A2');

        $this->hold($first);
        $this->hold($second);

        $holds = $this->asVenue(static fn () => ReservationHold::query()->get());

        $this->assertCount(1, $holds);
        $this->assertSame($second->id, $holds->first()->resource_id);
    }

    #[Test]
    public function the_price_comes_from_the_rules_and_not_from_the_form(): void
    {
        $this->asVenue(fn () => PriceRule::factory()
            ->percent(50)
            ->create(['scope_id' => $this->bookingType->id, 'name' => 'Weekend rate']));

        $hold = $this->hold($this->spot('A1'));

        $this->post(route('public.book.store', [$this->venueTenant, $hold]), [
            'name' => 'Price Hacker',
            'email' => 'hacker@example.com',
            'calling_code' => '+30',
            'phone' => '6941234567',
            'terms' => '1',

            // Whatever a tampered form might send.
            'amount_cents' => 1,
            'price' => '0.01',
        ])->assertRedirect();

        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());

        // €30 for that set, plus the rule's 50%.
        $this->assertSame(4500, $reservation->amount_cents);
    }

    #[Test]
    public function the_details_form_insists_on_what_the_venue_needs(): void
    {
        $hold = $this->hold($this->spot('A1'));

        $this->post(route('public.book.store', [$this->venueTenant, $hold]), [
            'name' => '',
            'email' => 'not-an-email',
            'calling_code' => '+30',
            'phone' => '123',
        ])->assertSessionHasErrors(['name', 'email', 'terms']);

        $this->assertSame(0, $this->asVenue(static fn (): int => Reservation::query()->count()));
    }

    #[Test]
    public function a_bot_that_fills_the_hidden_field_gets_nowhere(): void
    {
        $hold = $this->hold($this->spot('A1'));

        $this->post(route('public.book.store', [$this->venueTenant, $hold]), [
            'name' => 'Spam Bot',
            'email' => 'bot@example.com',
            'calling_code' => '+30',
            'phone' => '6941234567',
            'terms' => '1',
            'website' => 'https://example.com',
        ])->assertSessionHasErrors('website');

        $this->assertSame(0, $this->asVenue(static fn (): int => Reservation::query()->count()));
    }

    #[Test]
    public function one_email_cannot_take_the_whole_front_row(): void
    {
        foreach (['A1', 'A2'] as $label) {
            $hold = $this->hold($this->spot($label));

            $this->post(route('public.book.store', [$this->venueTenant, $hold]), [
                'name' => 'Greedy Guest',
                'email' => 'greedy@example.com',
                'calling_code' => '+30',
                'phone' => '6941234567',
                'terms' => '1',
            ])->assertRedirect();
        }

        $third = $this->hold($this->spot('A3'));

        $this->post(route('public.book.store', [$this->venueTenant, $third]), [
            'name' => 'Greedy Guest',
            'email' => 'greedy@example.com',
            'calling_code' => '+30',
            'phone' => '6941234567',
            'terms' => '1',
        ])->assertSessionHasErrors('email');

        $this->assertSame(2, $this->asVenue(static fn (): int => Reservation::query()->count()));
    }

    #[Test]
    public function a_hold_that_belongs_to_another_browser_is_not_yours(): void
    {
        $hold = $this->hold($this->spot('A1'));

        $this->flushSession();

        $this->get(route('public.book', [$this->venueTenant, $hold]))->assertForbidden();

        $this->post(route('public.book.store', [$this->venueTenant, $hold]), [
            'name' => 'Someone Else',
            'email' => 'else@example.com',
            'calling_code' => '+30',
            'phone' => '6941234567',
            'terms' => '1',
        ])->assertForbidden();
    }

    #[Test]
    public function an_expired_hold_sends_the_guest_back_to_choose_again(): void
    {
        $hold = $this->hold($this->spot('A1'));

        $this->travel(16)->minutes();

        $this->get(route('public.book', [$this->venueTenant, $hold]))
            ->assertRedirect(route('public.search', [$this->venueTenant, 'people' => 2]));
    }

    #[Test]
    public function a_closed_day_says_so_instead_of_showing_an_empty_page(): void
    {
        $this->asVenue(fn () => app(\App\Actions\Closures\CloseBookings::class)->handle(
            subject: $this->bookingType,
            dateFrom: self::DATE,
            reason: 'Private event',
        ));

        $this->get(route('public.search', [
            $this->venueTenant,
            'type' => 'sunbeds',
            'people' => 2,
            'date' => self::DATE,
        ]))->assertOk()->assertSee('Nothing free that day');
    }

    #[Test]
    public function a_date_in_the_past_quietly_becomes_today(): void
    {
        $this->get(route('public.search', [
            $this->venueTenant,
            'type' => 'sunbeds',
            'people' => 2,
            'date' => '2020-01-01',
        ]))->assertOk()->assertSee(CarbonImmutable::now('Europe/Athens')->format('l j F'));
    }

    #[Test]
    public function a_lapsed_subscription_stops_new_bookings_politely(): void
    {
        $this->venueTenant->forceFill([
            'status' => \App\Enums\TenantStatus::Expired,
            'trial_ends_at' => null,
        ])->save();

        $this->get(route('public.search', [$this->venueTenant, 'type' => 'sunbeds']))
            ->assertStatus(503)
            ->assertSee('temporarily unavailable');
    }

    private function spot(string $label): Resource
    {
        return $this->asVenue(static fn (): Resource => Resource::query()->where('label', $label)->firstOrFail());
    }

    private function hold(Resource $resource): ReservationHold
    {
        $this->post(route('public.hold', $this->venueTenant), [
            'resource' => $resource->id,
            'starts_at' => $this->athens('11:00')->toIso8601String(),
            'people' => 2,
        ]);

        return $this->asVenue(static fn (): ReservationHold => ReservationHold::query()
            ->orderByDesc('id')
            ->firstOrFail());
    }

    private function athens(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DATE.' '.$time, 'Europe/Athens')->utc();
    }

    /**
     * Reads the venue's own data the way staff would, since the guest's
     * requests resolve the tenant from the URL and leave nothing bound.
     */
    private function asVenue(Closure $callback): mixed
    {
        return app(\App\Tenancy\TenantContext::class)->asTenant($this->venueTenant, $callback);
    }
}
