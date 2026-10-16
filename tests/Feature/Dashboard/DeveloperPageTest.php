<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ApiTokenMode;
use App\Models\ApiRequest;
use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Settings → Developer (SPEC §15.2).
 *
 * Two things carry weight here. A token must never be printed by a GET, so it
 * cannot end up in a browser history or a proxy log; and replacing one has to
 * take effect at once, because the reason anyone presses that button is that
 * the old one has leaked.
 */
final class DeveloperPageTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function the_first_visit_issues_both_tokens(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $tokens = $this->asTenant(static fn () => ApiToken::query()->orderBy('mode')->get());

        $this->assertCount(2, $tokens);
        $this->assertSame(['live', 'test'], $tokens->pluck('mode.value')->all());
    }

    #[Test]
    public function the_page_shows_the_token_masked_and_never_in_full(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $token = $this->asTenant(
            static fn (): ApiToken => ApiToken::query()->where('mode', ApiTokenMode::Live)->sole()
        );

        $this->get(route('dashboard.developer'))
            ->assertOk()
            ->assertSee($token->prefix)
            ->assertDontSee($token->token);
    }

    #[Test]
    public function revealing_takes_a_post(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $token = $this->asTenant(
            static fn (): ApiToken => ApiToken::query()->where('mode', ApiTokenMode::Live)->sole()
        );

        $this->post(route('dashboard.developer.reveal', 'live'))
            ->assertRedirect();

        $this->followingRedirects()
            ->post(route('dashboard.developer.reveal', 'live'))
            ->assertOk()
            ->assertSee($token->token);
    }

    #[Test]
    public function replacing_a_token_kills_the_old_one_immediately(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $before = $this->asTenant(
            static fn (): ApiToken => ApiToken::query()->where('mode', ApiTokenMode::Live)->sole()
        );

        $this->post(route('dashboard.developer.regenerate', 'live'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $after = $this->asTenant(
            static fn (): ApiToken => ApiToken::query()->where('mode', ApiTokenMode::Live)->sole()
        );

        $this->assertNotSame($before->token, $after->token);

        // Not merely marked revoked: the row is gone, so the old secret
        // matches nothing at all.
        $this->assertSame(0, ApiToken::query()->acrossTenants()
            ->where('token_hash', ApiToken::hash((string) $before->token))->count());

        // And the old one is refused at the door.
        $this->getJson('/api/v1/venue', ['Authorization' => 'Bearer '.$before->token])
            ->assertStatus(401);
    }

    #[Test]
    public function the_test_token_is_separate_from_the_live_one(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $this->post(route('dashboard.developer.regenerate', 'test'))->assertRedirect();

        $live = $this->asTenant(
            static fn (): ApiToken => ApiToken::query()->where('mode', ApiTokenMode::Live)->sole()
        );

        // Replacing one must not disturb the other, or a developer testing
        // their form takes the venue's bookings down with them.
        $this->getJson('/api/v1/venue', ['Authorization' => 'Bearer '.$live->token])
            ->assertOk();
    }

    #[Test]
    public function an_exposed_token_is_called_out_by_name(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $this->asTenant(static function (): void {
            ApiToken::query()->where('mode', ApiTokenMode::Live)->sole()->forceFill([
                'exposed_at' => now(),
                'last_origin' => 'https://crystalseaside.gr',
            ])->save();
        });

        $this->get(route('dashboard.developer'))
            ->assertOk()
            ->assertSee('looks exposed')
            ->assertSee('crystalseaside.gr');
    }

    #[Test]
    public function the_usage_figures_separate_refusals_from_calls(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $this->asTenant(static function (): void {
            $token = ApiToken::query()->where('mode', ApiTokenMode::Live)->sole();

            foreach ([200, 200, 200, 422] as $status) {
                ApiRequest::query()->create([
                    'api_token_id' => $token->getKey(),
                    'method' => 'GET',
                    'endpoint' => 'api/v1/times',
                    'status' => $status,
                    'duration_ms' => 12,
                    'occurred_at' => now(),
                ]);
            }
        });

        $this->get(route('dashboard.developer'))
            ->assertOk()
            ->assertSee('4 calls')
            ->assertSee('1 refused')
            ->assertSee('api/v1/times');
    }

    #[Test]
    public function the_page_carries_the_venues_own_slug_ready_to_paste(): void
    {
        $this->get(route('dashboard.developer'))
            ->assertOk()
            ->assertSee('/api/v1/times', false)
            ->assertSee('Authorization: Bearer YOUR_TOKEN', false)
            ->assertSee('openapi.json');
    }

    #[Test]
    public function the_schema_is_public_and_describes_every_endpoint(): void
    {
        auth()->logout();

        $response = $this->getJson(route('api.v1.openapi'))->assertOk();

        $this->assertSame('3.1.0', $response->json('openapi'));

        foreach (['/venue', '/booking-types', '/days', '/times', '/availability', '/booking-url'] as $path) {
            $this->assertArrayHasKey($path, $response->json('paths'), "{$path} is missing from the schema.");
        }
    }

    #[Test]
    public function an_unknown_mode_is_not_a_token(): void
    {
        $this->post(route('dashboard.developer.regenerate', 'production'))
            ->assertNotFound();
    }

    #[Test]
    public function another_account_cannot_reach_this_ones_token(): void
    {
        $this->get(route('dashboard.developer'))->assertOk();

        $theirs = $this->asTenant(
            static fn (): ApiToken => ApiToken::query()->where('mode', ApiTokenMode::Live)->sole()
        );

        // A second owner, their own account, their own page.
        $this->signInAsOwner('Other Taverna');

        $this->get(route('dashboard.developer'))
            ->assertOk()
            ->assertDontSee($theirs->prefix)
            ->assertDontSee($theirs->token);
    }
}
