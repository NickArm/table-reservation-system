<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * One page for everything set once (SPEC §1.1).
 *
 * The point of it is the status line on each card. A hub that is only a menu
 * is the rail with more words — it earns its place by answering "what did I
 * put there" without opening anything.
 */
final class SetupHubTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function an_empty_account_is_told_what_is_missing_rather_than_shown_a_menu(): void
    {
        $this->get(route('dashboard.setup'))
            ->assertOk()
            ->assertSee('Nothing to book yet')
            ->assertSee('Nothing published')
            ->assertSee('Nothing switched on');
    }

    #[Test]
    public function each_card_says_what_it_is_set_to_now(): void
    {
        $this->asTenant(function (): void {
            $type = $this->tableType();
            $area = $this->areaIn($type, 'Terrace');
            $this->resourcesIn($area, 'T', 4);
        });

        // Four tables of four, in one area: the numbers are read from what is
        // configured, not from a sentence somebody typed once.
        $this->get(route('dashboard.setup'))
            ->assertOk()
            ->assertSee('4 spots seating 16 across 1 area');
    }

    #[Test]
    public function the_profile_card_carries_how_full_the_profile_is(): void
    {
        $this->asTenant(fn () => Venue::query()->firstOrFail()->forceFill([
            'description' => 'A quiet bay with clear water.',
            'address' => 'Kalami',
            'city' => 'Corfu',
            'phone' => '+302663000000',
        ])->save());

        $this->get(route('dashboard.setup'))
            ->assertOk()
            ->assertSee('% filled');
    }

    #[Test]
    public function the_rail_leads_here_rather_than_listing_every_setting(): void
    {
        /*
         * The rail is what is touched during service. Ten settings beside the
         * four that matter is how a menu becomes a list somebody reads every
         * time instead of remembering.
         */
        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee(route('dashboard.setup'))
            ->assertDontSee(route('dashboard.developer'))
            ->assertDontSee(route('dashboard.billing'));
    }

    #[Test]
    public function another_venue_sees_its_own_setup(): void
    {
        $this->asTenant(function (): void {
            $type = $this->tableType();
            $area = $this->areaIn($type, 'Terrace');
            $this->resourcesIn($area, 'T', 4);
        });

        $this->signInAsOwner('Another Place');

        $this->get(route('dashboard.setup'))
            ->assertOk()
            ->assertSee('Nothing to book yet');
    }

    #[Test]
    public function every_screen_under_setup_has_a_way_back_to_it(): void
    {
        /*
         * The rail points at the hub and nothing else, so without this the
         * browser's own back button was the only route out of eleven screens
         * — and from inside a booking type's areas, two presses of it.
         */
        $screens = [
            'dashboard.profile.edit',
            'dashboard.branding.edit',
            'dashboard.billing',
            'dashboard.payments.edit',
            'dashboard.notifications.edit',
            'dashboard.legal.edit',
            'dashboard.widget',
            'dashboard.developer',
            'dashboard.settings',
            'dashboard.booking-types.index',
        ];

        foreach ($screens as $screen) {
            $this->get(route($screen))
                ->assertOk()
                ->assertSee(route('dashboard.setup'), false);
        }
    }

    #[Test]
    public function adding_something_is_offered_at_the_top_rather_than_the_foot(): void
    {
        /*
         * A fold at the foot of a list sits furthest from the heading that
         * explains it and closest to the next section's heading, which is
         * where it gets read as belonging to the wrong thing. Every "add"
         * control is in the head of what it adds to, and opens its fold
         * through the query string — no JavaScript, and the back button
         * closes it again.
         */
        $this->asTenant(function (): void {
            $type = $this->tableType();
            $this->areaIn($type, 'Terrace');
        });

        $this->get(route('dashboard.booking-types.index'))
            ->assertOk()
            // Offered beside the title, before the page's content.
            ->assertSeeInOrder(['Add something to book', 'View your page', 'setup__card'])
            ->assertSee('id="add-type"', false);

        // And asking for it opens the form rather than landing on a fold the
        // owner has to find and press a second time.
        $this->get(route('dashboard.booking-types.index', ['add' => 'type']))
            ->assertOk()
            ->assertSee('id="add-type" open', false);
    }

    #[Test]
    public function every_section_of_a_booking_type_offers_its_own_add(): void
    {
        $type = $this->asTenant(function () {
            $type = $this->tableType();
            $this->areaIn($type, 'Terrace');

            return $type;
        });

        $this->get(route('dashboard.booking-types.show', $type))
            ->assertOk()
            ->assertSee('Add a price rule')
            ->assertSee('Close a date')
            ->assertSee('Add an area')
            // Areas gained a heading of their own: an unlabelled fold under
            // the closed dates reads as belonging to the closed dates.
            ->assertSee('<h2 class="h2">Areas</h2>', false);
    }

    #[Test]
    public function the_hub_and_the_screen_under_it_are_not_both_called_setup(): void
    {
        // Two screens with the same heading is how somebody stops being able
        // to tell which one they are on.
        $this->get(route('dashboard.booking-types.index'))
            ->assertOk()
            ->assertSee('Tables and sunbeds')
            ->assertDontSee('<h1>Setup</h1>', false);
    }

    #[Test]
    public function a_booking_type_says_where_it_sits(): void
    {
        $type = $this->asTenant(fn () => $this->tableType());

        $this->get(route('dashboard.booking-types.show', $type))
            ->assertOk()
            ->assertSeeInOrder(['Setup', 'Tables and sunbeds', $type->name]);
    }
}
