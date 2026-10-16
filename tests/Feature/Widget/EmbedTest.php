<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Enums\Channel;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * The embeddable widget (SPEC §9).
 *
 * Most bookings are meant to arrive through this, so the tests are about the
 * things that would silently stop that happening: the file staying at its
 * address, the no-script fallback actually submitting somewhere, and the
 * channel being recorded so an owner can see it working.
 */
final class EmbedTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    private Tenant $venueTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
        $this->venueTenant = $this->tenant;

        $this->asTenant(function (): void {
            $type = $this->sunbedType(['name' => 'Sunbeds']);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 3, ['base_price_cents' => 3000]);
        });

        auth()->logout();
        app(TenantContext::class)->forget();
    }

    #[Test]
    public function the_script_is_a_plain_file_at_a_permanent_address(): void
    {
        // Not built by Vite and not hashed, because this address is pasted
        // into customers' sites and must survive every deploy (SPEC §9.2).
        $path = public_path('embed.js');

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('customElements.define', $source);
        $this->assertStringContainsString('attachShadow', $source);

        // The budget in the spec is 10KB gzipped.
        $this->assertLessThan(
            10 * 1024,
            strlen((string) gzencode($source, 9)),
            'embed.js has grown past its 10KB gzipped budget.'
        );
    }

    #[Test]
    public function the_iframe_fallback_renders_a_working_form(): void
    {
        $response = $this->get(route('embed.search', $this->venueTenant))->assertOk();

        // Targets the top window, so pressing Search moves the page the
        // iframe sits in rather than the frame (SPEC §9.2).
        $response->assertSee('target="_top"', false);
        $response->assertSee(route('public.search', $this->venueTenant), false);

        // Attribution travels with it.
        $response->assertSee('name="channel" value="widget"', false);

        // Times come from the engine, so the dropdown holds nothing that
        // would then be refused.
        $response->assertSee('10:00');
    }

    #[Test]
    public function the_fallback_says_it_may_be_framed(): void
    {
        $this->get(route('embed.search', $this->venueTenant))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', 'frame-ancestors *');
    }

    #[Test]
    public function the_fallback_needs_no_javascript_to_reach_the_booking_page(): void
    {
        // Exactly what the form above would submit.
        $this->get(route('public.search', [
            $this->venueTenant,
            'type' => 'sunbeds',
            'people' => 2,
            'date' => self::DATE,
            'channel' => 'widget',
        ]))->assertOk();
    }

    #[Test]
    public function a_closed_day_explains_itself_rather_than_showing_an_empty_dropdown(): void
    {
        $this->get(route('embed.search', [$this->venueTenant, 'date' => '2026-12-25']))
            ->assertOk()
            ->assertSee('Nothing free');
    }

    #[Test]
    public function the_widget_sighting_is_recorded_from_the_origin_header(): void
    {
        $this->getJson(
            "/api/internal/{$this->venueTenant->slug}/booking-types",
            ['Origin' => 'https://crystalseaside.gr'],
        )->assertOk();

        $venue = $this->asTenant(static fn (): Venue => Venue::query()->sole());

        $this->assertSame('https://crystalseaside.gr', $venue->widget_last_origin);
        $this->assertTrue($venue->widgetIsLive());
    }

    #[Test]
    public function the_widget_endpoints_answer_a_cross_origin_browser(): void
    {
        // The widget runs on somebody else's domain, so without these the
        // browser throws the response away before the widget sees it.
        $this->getJson(
            "/api/internal/{$this->venueTenant->slug}/times?type=sunbeds&date=".self::DATE,
            ['Origin' => 'https://crystalseaside.gr'],
        )
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Vary', 'Origin');
    }

    #[Test]
    public function a_sighting_is_not_recorded_for_a_call_without_an_origin(): void
    {
        $this->getJson("/api/internal/{$this->venueTenant->slug}/booking-types")->assertOk();

        $venue = $this->asTenant(static fn (): Venue => Venue::query()->sole());

        $this->assertNull($venue->widget_last_seen_at);
    }

    #[Test]
    public function a_booking_that_arrived_through_the_widget_says_so(): void
    {
        $spot = $this->asTenant(
            static fn () => \App\Models\Resource::query()->where('label', 'A1')->firstOrFail()
        );

        $this->post(route('public.hold', $this->venueTenant), [
            'resource' => $spot->id,
            'starts_at' => CarbonImmutable::parse(self::DATE.' 11:00', 'Europe/Athens')->utc()->toIso8601String(),
            'people' => 2,
            'channel' => 'widget',
            'ref' => 'crystalseaside.gr',
        ]);

        $hold = $this->asTenant(static fn () => \App\Models\ReservationHold::query()->firstOrFail());

        $this->post(route('public.book.store', [$this->venueTenant, $hold]), [
            'name' => 'Anna Muller',
            'email' => 'anna@example.com',
            'calling_code' => '+49',
            'phone' => '15123456789',
            'terms' => '1',
            'channel' => 'widget',
            'ref' => 'crystalseaside.gr',
        ]);

        $reservation = $this->asTenant(static fn (): Reservation => Reservation::query()->firstOrFail());

        // The number that makes an owner renew: where bookings come from
        // (SPEC §9.5).
        $this->assertSame(Channel::Widget, $reservation->channel);
    }
}
