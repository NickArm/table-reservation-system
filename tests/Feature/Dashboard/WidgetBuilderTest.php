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
 * The widget builder (SPEC §9.4).
 *
 * The owner's whole job on this screen is to copy one line and forward it, so
 * what matters is that the line is right and that the page can tell them
 * whether it ever reached their site.
 */
final class WidgetBuilderTest extends TestCase
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
    public function the_page_offers_every_shape_and_behaviour_the_spec_names(): void
    {
        $response = $this->get(route('dashboard.widget'))->assertOk();

        // Three shapes (§9.1).
        $response->assertSee('value="search"', false)
            ->assertSee('value="button"', false)
            ->assertSee('value="inline"', false);

        // Four things Search can do (§9.3).
        $response->assertSee('value="redirect"', false)
            ->assertSee('value="redirect_blank"', false)
            ->assertSee('value="modal"', false);
    }

    #[Test]
    public function the_preview_loads_the_real_script_rather_than_a_drawing(): void
    {
        $response = $this->get(route('dashboard.widget'))->assertOk();

        // The file itself, at its permanent address — not a Vite-built copy
        // under /build, whose name changes on every deploy and would break
        // every customer's page (SPEC §9.2).
        $response->assertSee('embed.js');
        $response->assertDontSee('build/assets/embed');
    }

    #[Test]
    public function the_iframe_alternative_is_offered_with_the_venues_own_address(): void
    {
        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertSee(route('embed.search', $this->tenant), false);
    }

    #[Test]
    public function the_paste_instructions_name_the_tools_people_actually_use(): void
    {
        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertSee('WordPress')
            ->assertSee('Custom HTML')
            ->assertSee('Elementor');
    }

    #[Test]
    public function an_unseen_widget_says_so_plainly(): void
    {
        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertSee('Not seen on any site yet');
    }

    #[Test]
    public function a_live_widget_is_reported_with_the_site_and_the_time(): void
    {
        $this->asTenant(static function (): void {
            Venue::query()->sole()->forceFill([
                'widget_last_origin' => 'https://crystalseaside.gr',
                'widget_last_seen_at' => now()->subMinutes(20),
            ])->save();
        });

        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertSee('Working on https://crystalseaside.gr')
            ->assertSee('20 minutes ago');
    }

    #[Test]
    public function a_widget_that_has_gone_quiet_is_flagged_rather_than_claimed(): void
    {
        $this->asTenant(static function (): void {
            Venue::query()->sole()->forceFill([
                'widget_last_origin' => 'https://crystalseaside.gr',
                'widget_last_seen_at' => now()->subMonth(),
            ])->save();
        });

        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertSee('nothing since')
            ->assertDontSee('Working on');
    }

    #[Test]
    public function the_booking_type_selector_only_appears_when_there_is_a_choice(): void
    {
        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertDontSee('Let the visitor choose');

        $this->asTenant(function (): void {
            $this->sunbedType(['name' => 'Sunbeds']);
            $this->tableType(['name' => 'Tables']);
        });

        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertSee('Let the visitor choose');
    }

    #[Test]
    public function another_account_sees_its_own_slug_and_not_this_ones(): void
    {
        $mine = $this->tenant->slug;

        $this->signInAsOwner('Other Taverna');

        $this->get(route('dashboard.widget'))
            ->assertOk()
            ->assertSee($this->tenant->slug, false)
            ->assertDontSee('data-venue="'.$mine.'"', false);
    }
}
