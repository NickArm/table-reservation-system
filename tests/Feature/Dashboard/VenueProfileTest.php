<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\VenueCategory;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The venue's public profile (SPEC §33.1, §33.2).
 *
 * The screen that was missing for the whole of §33: the fields have been in
 * the database since M0 and the booking page has read them since M4, but
 * until now the only way to fill them in was the seeder — so the SEO work
 * built in M4 had nothing to describe on any real account.
 */
final class VenueProfileTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function the_screen_says_what_is_missing_without_telling_anybody_off(): void
    {
        $this->get(route('dashboard.profile.edit'))
            ->assertOk()

            // A percentage and a list, because none of this is needed to take
            // a booking — it is what decides whether anybody finds the venue.
            ->assertSee('How complete it is')
            ->assertSee('None of it stops you taking bookings');
    }

    #[Test]
    public function the_profile_saves_and_reaches_the_public_page(): void
    {
        $this->patch(route('dashboard.profile.update'), $this->fields())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $venue = $this->venue();

        $this->assertSame('Kalami Beach', $venue->address);
        $this->assertSame(VenueCategory::Taverna, $venue->venue_category);
        $this->assertSame(39.7345, $venue->latitude);

        $this->get(route('public.booking', $this->tenant))
            ->assertOk()
            ->assertSee('Kalami')

            // The structured data is built from these fields, and a taverna
            // is a Restaurant to a search engine (§33.2).
            ->assertSee('"@type":"Restaurant"', false)
            ->assertSee('"servesCuisine":"Greek"', false);
    }

    #[Test]
    public function a_beach_bar_is_a_bar_to_a_search_engine_and_its_cuisine_is_dropped(): void
    {
        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'venue_category' => VenueCategory::BeachBar->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('public.booking', $this->tenant))
            ->assertOk()
            ->assertSee('"@type":"BarOrPub"', false)

            // `servesCuisine` is only meaningful on a Restaurant. Sending it
            // anyway is structured data that says something untrue.
            ->assertDontSee('servesCuisine', false);
    }

    #[Test]
    public function half_a_coordinate_is_refused(): void
    {
        /*
         * Because the other half of it puts the pin in the Atlantic, and a
         * map with a wrong pin is worse for a venue than no map at all.
         *
         * The complaint lands on the field that is *missing*, not on the one
         * that was filled in: `required_with` fires on the absent half.
         */
        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'longitude' => null,
        ])->assertSessionHasErrors('longitude');

        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'latitude' => null,
        ])->assertSessionHasErrors('latitude');
    }

    #[Test]
    public function neither_coordinate_is_perfectly_acceptable(): void
    {
        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'latitude' => null,
            'longitude' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($this->venue()->latitude);
    }

    #[Test]
    public function a_price_range_outside_googles_own_vocabulary_is_refused(): void
    {
        // It goes straight into the structured data, so free text here would
        // be us publishing nonsense on the venue's behalf.
        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'price_range' => 'cheapish',
        ])->assertSessionHasErrors('price_range');
    }

    #[Test]
    public function only_the_name_is_actually_required(): void
    {
        $this->patch(route('dashboard.profile.update'), ['name' => ''])
            ->assertSessionHasErrors('name');

        // Everything else empty is a thin profile, not an error. Refusing to
        // save because the postcode is missing would be charging an owner for
        // something they never asked for.
        $this->patch(route('dashboard.profile.update'), ['name' => 'Just A Name'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Just A Name', $this->venue()->name);
    }

    #[Test]
    public function a_thin_profile_is_kept_out_of_search_results(): void
    {
        $this->patch(route('dashboard.profile.update'), [
            'name' => 'Nearly Empty',
            'is_indexable' => '1',
        ])->assertRedirect();

        /*
         * Held back on purpose even though the owner asked to be listed:
         * hundreds of near-identical thin pages are penalised across a whole
         * domain, which would cost every other venue here too (§33.1).
         */
        $this->assertFalse($this->venue()->shouldBeIndexed());

        $this->get(route('dashboard.profile.edit'))
            ->assertOk()
            // A fragment that sits on one line: `assertSee` matches the
            // rendered HTML as it is, newlines and all.
            ->assertSee('Your booking page is currently');
    }

    #[Test]
    public function turning_indexing_off_is_a_checkbox_that_can_actually_be_cleared(): void
    {
        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'is_indexable' => '1',
        ])->assertRedirect();

        $this->assertTrue((bool) $this->venue()->is_indexable);

        // An absent checkbox means off, which `fill()` alone cannot express:
        // the key simply is not in the request.
        $this->patch(route('dashboard.profile.update'), $this->fields())
            ->assertRedirect();

        $this->assertFalse((bool) $this->venue()->is_indexable);
    }

    #[Test]
    public function the_sitemap_lists_a_complete_venue_and_leaves_out_a_thin_one(): void
    {
        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'is_indexable' => '1',
        ])->assertRedirect();

        $complete = $this->tenant;

        // A second account with nothing filled in.
        $this->signInAsOwner('Nearly Empty Place');
        $thin = $this->tenant;

        auth()->logout();
        app(\App\Tenancy\TenantContext::class)->forget();

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(route('public.booking', $complete), false)

            // Listing four hundred thin pages is the fastest way to have the
            // whole domain treated as thin.
            ->assertDontSee(route('public.booking', $thin), false);
    }

    #[Test]
    public function another_venue_cannot_edit_this_ones_profile(): void
    {
        $this->patch(route('dashboard.profile.update'), $this->fields())->assertRedirect();

        $mine = $this->venue()->address;

        $this->signInAsOwner('Another Place');

        $this->patch(route('dashboard.profile.update'), [
            ...$this->fields(),
            'address' => 'Somewhere Else',
        ])->assertRedirect();

        // Each resolves its own venue from the signed-in user, so there is no
        // address to edit in order to reach somebody else's.
        $this->assertSame('Somewhere Else', $this->venue()->address);
        $this->assertSame('Kalami Beach', $mine);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(): array
    {
        return [
            'name' => 'Demo Beach Bar',
            'description' => 'A quiet bay on the north-east coast, clear water and shade.',
            'venue_category' => VenueCategory::Taverna->value,
            'cuisine' => 'Greek',
            'price_range' => '€€',
            'address' => 'Kalami Beach',
            'city' => 'Kalami',
            'postal_code' => '49083',
            'region' => 'Corfu',
            'country' => 'GR',
            'latitude' => '39.7345',
            'longitude' => '19.9298',
            'phone' => '+302663000000',
            'public_email' => 'hello@demobeachbar.test',
            'website_url' => 'https://demobeachbar.test',
        ];
    }

    private function venue(): Venue
    {
        return $this->asTenant(static fn (): Venue => Venue::query()->firstOrFail());
    }
}
