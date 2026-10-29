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
}
