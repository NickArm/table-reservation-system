<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\Channel;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The searchable list of every booking (SPEC §13.1).
 *
 * The one thing most worth protecting is that **the CSV covers exactly what
 * the screen does**. An export that quietly includes a different set than the
 * list above it is a bug nobody reports and everybody acts on.
 */
final class ReservationListTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-afternoon in Corfu, so "today" is unambiguous.
        $this->travelTo(CarbonImmutable::parse('2026-07-15 15:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function an_empty_venue_is_told_it_has_no_bookings_rather_than_no_matches(): void
    {
        $this->get(route('dashboard.reservations.index'))
            ->assertOk()
            ->assertSee('No bookings yet')
            ->assertDontSee('Nothing matches that');
    }

    #[Test]
    public function the_search_finds_a_booking_by_name_phone_or_code(): void
    {
        $reservation = $this->booking('2026-07-16 20:00', [
            'customer_name' => 'Maria Kosta',
            'customer_phone' => '+306941234567',
        ]);

        $this->booking('2026-07-16 21:00', ['customer_name' => 'Yannis Other']);

        foreach (['kost', '694123', $reservation->code] as $term) {
            $this->get(route('dashboard.reservations.index', ['q' => $term]))
                ->assertOk()
                ->assertSee('Maria Kosta')
                ->assertDontSee('Yannis Other');
        }
    }

    #[Test]
    public function the_date_filter_counts_the_whole_of_the_last_day_in_local_time(): void
    {
        // Half past eleven at night on the 16th, Corfu time — which is already
        // the 17th in UTC. A filter built on UTC days would drop it.
        $late = $this->booking('2026-07-16 23:30', ['customer_name' => 'Late Night']);

        $this->booking('2026-07-17 20:00', ['customer_name' => 'Next Day']);

        $this->get(route('dashboard.reservations.index', ['from' => '2026-07-16', 'to' => '2026-07-16']))
            ->assertOk()
            ->assertSee('Late Night')
            ->assertDontSee('Next Day');

        $this->assertNotNull($late->id);
    }

    #[Test]
    public function status_and_channel_narrow_the_list(): void
    {
        $this->booking('2026-07-16 20:00', [
            'customer_name' => 'Did Not Come',
            'status' => ReservationStatus::NoShow,
        ]);

        $this->booking('2026-07-16 21:00', [
            'customer_name' => 'Rang Up',
            'channel' => Channel::Phone,
        ]);

        $this->get(route('dashboard.reservations.index', ['status' => ReservationStatus::NoShow->value]))
            ->assertOk()
            ->assertSee('Did Not Come')
            ->assertDontSee('Rang Up');

        $this->get(route('dashboard.reservations.index', ['channel' => Channel::Phone->value]))
            ->assertOk()
            ->assertSee('Rang Up')
            ->assertDontSee('Did Not Come');
    }

    #[Test]
    public function the_totals_cover_the_whole_filtered_set_not_the_page(): void
    {
        $this->booking('2026-07-16 20:00', ['party_size' => 2, 'amount_cents' => 3000]);
        $this->booking('2026-07-16 21:00', ['party_size' => 4, 'amount_cents' => 5000]);

        // Outside the filter, so it must not be counted.
        $this->booking('2026-07-20 20:00', ['party_size' => 8, 'amount_cents' => 9000]);

        $this->get(route('dashboard.reservations.index', ['from' => '2026-07-16', 'to' => '2026-07-16']))
            ->assertOk()
            // €30 + €50 for the two in range, not the €90 outside it.
            ->assertSee('€80')
            ->assertDontSee('€170');
    }

    #[Test]
    public function the_csv_covers_exactly_what_the_filters_cover(): void
    {
        $this->booking('2026-07-16 20:00', ['customer_name' => 'Μαρία Κώστα']);
        $this->booking('2026-07-20 20:00', ['customer_name' => 'Outside The Filter']);

        $response = $this->get(route('dashboard.reservations.export', [
            'from' => '2026-07-16',
            'to' => '2026-07-16',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Μαρία Κώστα', $csv);
        $this->assertStringNotContainsString('Outside The Filter', $csv);

        // Local dates and times, not the UTC the rows are stored in.
        $this->assertStringContainsString('2026-07-16,20:00', $csv);
    }

    #[Test]
    public function another_venues_bookings_are_not_in_the_list_or_the_export(): void
    {
        $this->booking('2026-07-16 20:00', ['customer_name' => 'Mine Only']);

        $this->signInAsOwner('Someone Else');

        $this->get(route('dashboard.reservations.index'))
            ->assertOk()
            ->assertDontSee('Mine Only');

        $this->assertStringNotContainsString(
            'Mine Only',
            $this->get(route('dashboard.reservations.export'))->streamedContent()
        );
    }

    #[Test]
    public function upcoming_and_past_are_one_tap_each(): void
    {
        $this->booking('2026-07-10 20:00', ['customer_name' => 'Last Week']);
        $this->booking('2026-07-20 20:00', ['customer_name' => 'Next Week']);

        /*
         * The chips are links with the dates already worked out. An owner
         * with a guest on the telephone should not be typing 2026-07-15 into
         * two date fields to answer "are they coming or have they been".
         */
        $this->get(route('dashboard.reservations.index', ['from' => '2026-07-15']))
            ->assertOk()
            ->assertSee('Next Week')
            ->assertDontSee('Last Week');

        $this->get(route('dashboard.reservations.index', ['to' => '2026-07-14']))
            ->assertOk()
            ->assertSee('Last Week')
            ->assertDontSee('Next Week');
    }

    #[Test]
    public function a_chip_keeps_the_search_that_is_already_typed(): void
    {
        $this->booking('2026-07-20 20:00', ['customer_name' => 'Maria Rossi']);

        // Losing the search term on the way to a date filter is how a screen
        // teaches somebody to use it twice.
        $this->get(route('dashboard.reservations.index', ['q' => 'Maria']))
            ->assertOk()
            // Escaped on purpose: the ampersand in the href is `&amp;` in
            // the HTML, and the default `assertSee` escapes to match it.
            ->assertSee(route('dashboard.reservations.index', ['q' => 'Maria', 'from' => '2026-07-15']));
    }

    #[Test]
    public function the_list_is_broken_up_by_day(): void
    {
        $this->booking('2026-07-20 20:00', ['customer_name' => 'Monday Guest']);
        $this->booking('2026-07-21 20:00', ['customer_name' => 'Tuesday Guest']);

        // Fifty rows of numbers with no date between them is a list somebody
        // counts down to find Friday.
        $this->get(route('dashboard.reservations.index'))
            ->assertOk()
            ->assertSeeInOrder(['Monday 20 July 2026', 'Monday Guest', 'Tuesday 21 July 2026', 'Tuesday Guest']);
    }

    #[Test]
    public function a_note_is_on_the_row_rather_than_behind_a_hover(): void
    {
        $this->booking('2026-07-20 20:00', [
            'customer_name' => 'Maria Rossi',
            'notes' => 'Allergic to shellfish',
        ]);

        $this->get(route('dashboard.reservations.index'))
            ->assertOk()
            ->assertSee('Allergic to shellfish');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(string $local, array $attributes = []): Reservation
    {
        return $this->asTenant(function () use ($local, $attributes): Reservation {
            $startsAt = CarbonImmutable::parse($local, 'Europe/Athens');

            return Reservation::factory()
                ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
                ->create([
                    'resource_snapshot' => ['label' => 'A1', 'area' => null, 'components' => null],
                    ...$attributes,
                ]);
        });
    }
}
