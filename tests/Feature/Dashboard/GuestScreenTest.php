<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\TenantRole;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The guests screen and the guest card (SPEC §28.3).
 */
final class GuestScreenTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function an_empty_list_explains_itself_instead_of_showing_a_table(): void
    {
        $this->get(route('dashboard.guests.index'))
            ->assertOk()
            ->assertSee('Nobody yet')
            ->assertSee('fills itself from every booking');
    }

    #[Test]
    public function the_list_shows_who_came_back_and_who_did_not_turn_up(): void
    {
        $this->asTenant(function (): void {
            Guest::factory()->regular(4)->create(['name' => 'Maria Kosta']);
            Guest::factory()->troublesome(2)->create(['name' => 'Yannis Late']);
            Guest::factory()->create(['name' => 'Anna Once']);
        });

        $this->get(route('dashboard.guests.index'))
            ->assertOk()
            ->assertSee('Maria Kosta')
            ->assertSee('Yannis Late')
            ->assertSee('2 no-shows')
            ->assertSee('regular');

        $this->get(route('dashboard.guests.index', ['show' => 'trouble']))
            ->assertOk()
            ->assertSee('Yannis Late')
            ->assertDontSee('Anna Once');

        $this->get(route('dashboard.guests.index', ['show' => 'repeat']))
            ->assertOk()
            ->assertSee('Maria Kosta')
            ->assertDontSee('Anna Once');
    }

    #[Test]
    public function the_segments_carry_their_counts_and_keep_the_search(): void
    {
        $this->asTenant(function (): void {
            Guest::factory()->regular(4)->create(['name' => 'Maria Kosta']);
            Guest::factory()->troublesome(2)->create(['name' => 'Yannis Late']);
            Guest::factory()->create(['name' => 'Anna Once']);
        });

        /*
         * The count is the reason this screen gets opened — "how many come
         * back" is the question, and a dropdown hides the answer until
         * somebody opens it.
         */
        $this->get(route('dashboard.guests.index'))
            ->assertOk()
            ->assertSeeInOrder(['Everyone', 'Came back', 'No-shows'])
            // Three guests in all, one of whom has been back.
            ->assertSee('<span class="chip__count">3</span>', false)
            ->assertSee('<span class="chip__count">1</span>', false);

        // And a segment does not throw away what is already typed.
        $this->get(route('dashboard.guests.index', ['q' => 'Maria']))
            ->assertOk()
            ->assertSee(route('dashboard.guests.index', ['q' => 'Maria', 'show' => 'repeat']));
    }

    #[Test]
    public function the_search_covers_name_email_and_phone(): void
    {
        $this->asTenant(function (): void {
            Guest::factory()->create([
                'name' => 'Maria Kosta',
                'email' => 'maria@example.test',
                'phone' => '+306941234567',
            ]);
            Guest::factory()->create(['name' => 'Yannis Other', 'email' => 'yannis@example.test']);
        });

        foreach (['kost', 'maria@', '694123'] as $term) {
            $this->get(route('dashboard.guests.index', ['q' => $term]))
                ->assertOk()
                ->assertSee('Maria Kosta')
                ->assertDontSee('Yannis Other');
        }
    }

    #[Test]
    public function the_card_shows_their_whole_history_with_this_venue(): void
    {
        [$guest, $reservation] = $this->asTenant(function (): array {
            $guest = Guest::factory()->regular(2)->create(['name' => 'Maria Kosta']);

            $reservation = Reservation::factory()->create([
                'customer_name' => 'Maria Kosta',
                'notes' => 'Prefers the shade.',
                'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
            ]);

            $reservation->forceFill(['guest_id' => $guest->id])->save();

            return [$guest, $reservation];
        });

        $this->get(route('dashboard.guests.show', $guest))
            ->assertOk()
            ->assertSee('Maria Kosta')
            ->assertSee('A1')
            ->assertSee('Prefers the shade.')
            ->assertSee($reservation->startsAtLocal('Europe/Athens')->format('j M Y'));
    }

    #[Test]
    public function the_card_offers_to_book_them_in(): void
    {
        $guest = $this->asTenant(fn (): Guest => Guest::factory()->create([
            'name' => 'Maria Kosta',
            'email' => 'maria@example.test',
            'phone' => '+306941234567',
        ]));

        $this->get(route('dashboard.guests.show', $guest))
            ->assertOk()
            ->assertSee('Book them in')
            ->assertSee(route('dashboard.bookings.create', ['guest' => $guest->id]));
    }

    #[Test]
    public function another_venues_guest_cannot_be_prefilled_by_guessing_an_id(): void
    {
        $guest = $this->asTenant(fn (): Guest => Guest::factory()->create(['name' => 'Maria Kosta']));

        $this->signInAsOwner('Another Place');

        // The tenant scope covers the prefill like every other read: the id
        // reaches nothing, and the form simply opens empty.
        $this->get(route('dashboard.bookings.create', ['guest' => $guest->id]))
            ->assertOk()
            ->assertDontSee('Maria Kosta');
    }

    #[Test]
    public function the_floor_note_is_at_the_top_of_the_card(): void
    {
        $guest = $this->asTenant(fn (): Guest => Guest::factory()->create([
            'name' => 'Maria Kosta',
            'internal_notes' => 'Allergic to shellfish.',
        ]));

        // It was only readable inside the edit box at the bottom, which is
        // not where anybody looks before a service.
        $this->get(route('dashboard.guests.show', $guest))
            ->assertOk()
            ->assertSeeInOrder(['What the floor should know', 'Allergic to shellfish.', 'History']);
    }

    #[Test]
    public function private_notes_and_tags_save(): void
    {
        $guest = $this->asTenant(static fn (): Guest => Guest::factory()->create());

        $this->patch(route('dashboard.guests.update', $guest), [
            'internal_notes' => 'Always asks for the front row.',
            'tags' => 'regular, VIP, , regular',
        ])->assertRedirect();

        $fresh = $this->asTenant(static fn (): Guest => Guest::query()->findOrFail($guest->id));

        $this->assertSame('Always asks for the front row.', $fresh->internal_notes);

        // Trimmed, de-duplicated, and the empty one dropped.
        $this->assertSame(['regular', 'VIP'], $fresh->tags);
    }

    #[Test]
    public function the_csv_carries_the_venues_own_data_including_its_notes(): void
    {
        $this->asTenant(function (): void {
            Guest::factory()->regular(3)->create([
                'name' => 'Μαρία Κώστα',
                'email' => 'maria@example.test',
                'internal_notes' => 'Prefers the front row.',
            ]);
        });

        $response = $this->get(route('dashboard.guests.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        // The byte order mark is what stops Excel on a Greek Windows machine
        // reading UTF-8 as Latin-1 and mangling every accented name.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Μαρία Κώστα', $csv);
        $this->assertStringContainsString('Prefers the front row.', $csv);
        $this->assertStringContainsString('maria@example.test', $csv);
    }

    #[Test]
    public function only_the_owner_can_erase_a_guest(): void
    {
        $guest = $this->asTenant(static fn (): Guest => Guest::factory()->create());

        $staff = User::forceCreate([
            'name' => 'Staff',
            'email' => 'staff@demo.test',
            'password' => 'correct-horse-battery',
            'email_verified_at' => now(),
        ]);

        $this->tenant->users()->attach($staff, ['role' => TenantRole::Staff->value]);

        // Erasing rewrites personal data on bookings that have already been
        // paid for, and it cannot be undone. That is an owner's decision.
        $this->actingAs($staff)
            ->get(route('dashboard.guests.show', $guest))
            ->assertOk()
            ->assertDontSee('Erase their details');

        $this->actingAs($staff)
            ->delete(route('dashboard.guests.erase', $guest))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->delete(route('dashboard.guests.erase', $guest))
            ->assertRedirect(route('dashboard.guests.index'));

        $this->assertNotNull(
            $this->asTenant(static fn (): ?Guest => Guest::query()->find($guest->id))?->anonymized_at
        );
    }

    #[Test]
    public function another_venues_guest_is_not_reachable_by_its_id(): void
    {
        $mine = $this->asTenant(static fn (): Guest => Guest::factory()->create());

        $this->signInAsOwner('Someone Else');

        // The tenant scope applies to route binding too, so guessing an id
        // gets a 404 rather than somebody else's guest (SPEC §28.1).
        $this->get(route('dashboard.guests.show', $mine))->assertNotFound();
    }
}
