<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiTokenMode;
use App\Models\ApiRequest;
use App\Models\ApiToken;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * The public API (SPEC §15.2).
 *
 * The test that matters most here is the scope one: a token belongs to one
 * account, and there must be no request that returns another's rows. The rest
 * check that the published shapes are the shapes we actually send, because a
 * versioned contract is only worth something if something enforces it.
 */
final class PublicApiTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        $this->token = 'tr_live_'.str_repeat('a', 40);

        $this->asTenant(function (): void {
            ApiToken::factory()->withToken($this->token)->create();

            $type = $this->sunbedType(['name' => 'Sunbeds']);
            $front = $this->areaIn($type, 'Front row', ['description' => 'Feet in the sand.']);
            $this->resourcesIn($front, 'A', 3, ['base_price_cents' => 3000]);
        });

        auth()->logout();
        app(TenantContext::class)->forget();
    }

    #[Test]
    public function the_venue_is_described_well_enough_to_build_a_form_against(): void
    {
        $this->get('/api/v1/venue', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.name', 'Demo Beach Bar')
            ->assertJsonPath('data.timezone', 'Europe/Athens')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonStructure(['data' => ['slug', 'name', 'timezone', 'currency', 'booking_url']]);
    }

    #[Test]
    public function the_booking_types_carry_the_bounds_a_date_picker_needs(): void
    {
        $this->get('/api/v1/booking-types', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'sunbeds')
            ->assertJsonStructure(['data' => [['slug', 'name', 'resource_type', 'max_advance_days', 'min_lead_time_minutes']]]);
    }

    #[Test]
    public function a_whole_month_comes_back_in_one_call(): void
    {
        $response = $this->get('/api/v1/days?type=sunbeds&month=2026-07', $this->auth())
            ->assertOk()
            ->assertJsonPath('meta.month', '2026-07');

        // July, every day of it, so a date picker can colour the grid without
        // asking thirty-one times.
        $this->assertCount(31, $response->json('data'));

        $this->assertContains(
            $response->json('data.3.status'),
            ['available', 'limited', 'full', 'closed'],
        );
    }

    #[Test]
    public function the_times_carry_what_is_free_and_the_cheapest_price(): void
    {
        $response = $this->get('/api/v1/times?type=sunbeds&date='.self::DATE.'&people=2', $this->auth())
            ->assertOk()
            ->assertJsonPath('meta.date', self::DATE);

        $first = $response->json('data.0');

        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $first['time']);
        $this->assertGreaterThan(0, $first['available']);
        $this->assertSame(3000, $first['from_price_cents']);
        $this->assertSame('€30', $first['from_price']);

        // Both the wall clock and the instant, so neither a form nor a
        // calendar has to work the other out.
        $this->assertNotEmpty($first['starts_at']);
    }

    #[Test]
    public function availability_at_one_time_is_grouped_by_area(): void
    {
        $response = $this->get(
            '/api/v1/availability?type=sunbeds&date='.self::DATE.'&time=11:00&people=2',
            $this->auth(),
        )->assertOk();

        $this->assertSame('Front row', $response->json('data.0.area.name'));
        $this->assertSame('Feet in the sand.', $response->json('data.0.area.description'));
        $this->assertSame(3, $response->json('data.0.available'));
        $this->assertSame('A1', $response->json('data.0.resources.0.label'));
        $this->assertSame(3000, $response->json('data.0.resources.0.price_cents'));
    }

    #[Test]
    public function the_booking_url_sends_the_visitor_to_us_with_the_channel_marked(): void
    {
        $url = $this->get('/api/v1/booking-url?type=sunbeds&people=4&date='.self::DATE, $this->auth())
            ->assertOk()
            ->json('data.url');

        $this->assertStringContainsString('/reservation/demo-beach-bar', $url);
        $this->assertStringContainsString('type=sunbeds', $url);
        $this->assertStringContainsString('people=4', $url);

        // The number that makes an owner renew: where bookings come from.
        $this->assertStringContainsString('channel=api', $url);
    }

    #[Test]
    public function a_token_sees_its_own_account_and_no_other(): void
    {
        // A second account, with a venue of its own and plenty of spots.
        $this->signInAsOwner('Other Taverna');

        $this->asTenant(function (): void {
            $type = $this->sunbedType(['name' => 'Sunbeds']);
            $area = $this->areaIn($type, 'Theirs');
            $this->resourcesIn($area, 'T', 9, ['base_price_cents' => 9900]);
        });

        auth()->logout();
        app(TenantContext::class)->forget();

        $response = $this->get(
            '/api/v1/availability?type=sunbeds&date='.self::DATE.'&time=11:00',
            $this->auth(),
        )->assertOk();

        // The first account's own three spots, at its own price. Nothing of
        // the second account's appears, and there is no parameter that could
        // ask for it: the tenant comes from the token and nowhere else.
        $this->assertSame('Front row', $response->json('data.0.area.name'));
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(3000, $response->json('data.0.resources.0.price_cents'));
    }

    #[Test]
    public function no_token_is_refused_with_an_instruction(): void
    {
        $this->getJson('/api/v1/venue')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'missing_token')
            ->assertJsonStructure(['error' => ['code', 'message']]);
    }

    #[Test]
    public function an_unknown_token_is_refused_and_recorded_nowhere(): void
    {
        $this->get('/api/v1/venue', ['Authorization' => 'Bearer tr_live_nope'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_token');

        // Nothing to show it to, so nothing is written.
        $this->assertSame(0, ApiRequest::query()->acrossTenants()->count());
    }

    #[Test]
    public function a_regenerated_token_stops_working_at_once(): void
    {
        $this->get('/api/v1/venue', $this->auth())->assertOk();

        $this->asTenant(fn () => app(\App\Actions\Api\IssueApiToken::class)->handle(ApiTokenMode::Live));

        $this->get('/api/v1/venue', $this->auth())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_token');
    }

    #[Test]
    public function a_token_arriving_from_a_browser_is_flagged_as_exposed(): void
    {
        // A server-to-server call sends no Origin. This one did, so the token
        // is sitting in somebody's JavaScript (SPEC §15.2.1).
        $this->get('/api/v1/venue', [
            ...$this->auth(),
            'Origin' => 'https://crystalseaside.gr',
        ])->assertOk();

        $token = $this->asTenant(static fn (): ApiToken => ApiToken::query()->sole());

        $this->assertTrue($token->isExposed());
        $this->assertSame('https://crystalseaside.gr', $token->last_origin);
    }

    #[Test]
    public function a_normal_call_is_not_flagged(): void
    {
        $this->get('/api/v1/venue', $this->auth())->assertOk();

        $token = $this->asTenant(static fn (): ApiToken => ApiToken::query()->sole());

        $this->assertFalse($token->isExposed());
        $this->assertNotNull($token->last_used_at);
    }

    #[Test]
    public function every_authenticated_call_is_logged_against_the_account(): void
    {
        $this->get('/api/v1/venue', $this->auth())->assertOk();
        $this->get('/api/v1/times?type=sunbeds&date='.self::DATE, $this->auth())->assertOk();

        $logged = $this->asTenant(static fn () => ApiRequest::query()->orderBy('id')->get());

        $this->assertCount(2, $logged);

        // The route pattern, not the filled-in path: grouping by endpoint is
        // the point, and a query string would drag a date into the table.
        $this->assertSame('api/v1/venue', $logged[0]->endpoint);
        $this->assertSame('api/v1/times', $logged[1]->endpoint);
        $this->assertSame(200, $logged[1]->status);
    }

    #[Test]
    public function a_bad_date_is_told_which_parameter_is_wrong(): void
    {
        $this->get('/api/v1/times?type=sunbeds&date=next+tuesday', $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonStructure(['error' => ['code', 'message']]);
    }

    #[Test]
    public function a_booking_type_that_does_not_exist_is_a_plain_404(): void
    {
        $this->get('/api/v1/times?type=helicopters&date='.self::DATE, $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    #[Test]
    public function the_widget_reads_the_same_shapes_without_a_token(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-beach-bar')->sole();

        $response = $this->getJson("/api/internal/{$tenant->slug}/times?type=sunbeds&date=".self::DATE)
            ->assertOk();

        // Identical to the documented endpoint, because it is the same
        // controller. The widget cannot drift from what customers read about.
        $this->assertSame(3000, $response->json('data.0.from_price_cents'));

        // Public, so nothing is logged against anybody's token.
        $this->assertSame(0, ApiRequest::query()->acrossTenants()->count());
    }

    #[Test]
    public function the_widget_endpoint_refuses_an_unknown_venue(): void
    {
        $this->getJson('/api/internal/nobody/times?type=sunbeds&date='.self::DATE)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }
}
