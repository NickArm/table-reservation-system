<?php

declare(strict_types=1);

namespace Tests\Feature\Legal;

use App\Enums\TenantStatus;
use App\Enums\VenueDocumentType;
use App\Models\LegalDocument;
use App\Models\Reservation;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Tenancy\TenantContext;
use App\Vouchers\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * A venue's own terms and cancellation policy (SPEC §12.1).
 *
 * The consent box at checkout has existed since M4 with nothing behind it:
 * guests have been agreeing to documents that did not exist. This closes
 * that, and the half of it worth testing hardest is the snapshot — the only
 * version of a term that means anything is the one the guest was shown.
 */
final class VenueDocumentsTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function the_screen_offers_wording_that_already_works_rather_than_a_blank_box(): void
    {
        /*
         * A blank textarea headed "Terms of Booking" in front of a taverna
         * owner produces either nothing or a paragraph copied off a
         * competitor — and the consent box at checkout then agrees to it.
         */
        $this->get(route('dashboard.legal.edit'))
            ->assertOk()
            ->assertSee('Terms of Booking')
            ->assertSee('Cancellation Policy')
            ->assertSee('deposit against your final')
            ->assertSee('suggested wording');

        // Suggested, not saved. Nothing is live until they press the button.
        $this->assertSame(0, LegalDocument::query()->acrossTenants()->count());
    }

    #[Test]
    public function saving_publishes_it_immediately(): void
    {
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => '## Refunds'."\n\n".'Cancel 48 hours before and we refund in full.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // No draft step: the author has just corrected a sentence and expects
        // it to be corrected (§1.1).
        $document = $this->asTenant(static fn (): ?LegalDocument => LegalDocument::current(
            VenueDocumentType::CancellationPolicy
        ));

        $this->assertNotNull($document);
        $this->assertSame(1, (int) $document->version);

        $this->get(route('public.legal', [$this->tenant, 'cancellation-policy']))
            ->assertOk()
            ->assertSee('Cancel 48 hours before');
    }

    #[Test]
    public function saving_again_writes_a_new_version_and_keeps_the_old_one(): void
    {
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'The first wording, which guests agreed to.',
        ])->assertRedirect();

        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'The second wording, stricter than the first one.',
        ])->assertRedirect();

        $versions = $this->asTenant(static fn () => LegalDocument::query()
            ->where('type', VenueDocumentType::CancellationPolicy->value)
            ->orderBy('version')
            ->get());

        // A version is never edited: a booking points at the one its guest
        // agreed to, and rewriting it would change what was agreed.
        $this->assertCount(2, $versions);
        $this->assertStringContainsString('first wording', (string) $versions[0]->content);
        $this->assertFalse((bool) $versions[0]->is_current);
        $this->assertTrue((bool) $versions[1]->is_current);
    }

    #[Test]
    public function saving_the_same_text_twice_writes_nothing(): void
    {
        $text = 'Cancel at least 24 hours before and we refund in full.';

        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), ['content' => $text]);
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), ['content' => $text]);

        // Somebody who opens the screen, reads it and presses Save should not
        // generate a version.
        $this->assertSame(1, $this->asTenant(static fn (): int => LegalDocument::query()
            ->where('type', VenueDocumentType::CancellationPolicy->value)
            ->count()));
    }

    #[Test]
    public function two_words_are_refused(): void
    {
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'No refunds.',
        ])->assertSessionHasErrors('content');

        // An almost-empty policy is worse than none: the consent box would be
        // asking a guest to agree to two words, and the venue would rely on it.
        $this->assertSame(0, LegalDocument::query()->acrossTenants()->count());
    }

    #[Test]
    public function the_checkout_links_to_the_documents_once_they_exist(): void
    {
        $this->buildASpot();

        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'Cancel at least 24 hours before and we refund in full.',
        ])->assertRedirect();

        $hold = $this->holdASpot();

        $this->get(route('public.book', [$this->tenant, $hold]))
            ->assertOk()
            ->assertSee(route('public.legal', [$this->tenant, 'cancellation-policy']), false)
            ->assertSee('Cancel at least 24 hours before');
    }

    #[Test]
    public function a_document_the_venue_never_wrote_is_not_linked_to(): void
    {
        $this->buildASpot();

        $hold = $this->holdASpot();

        // A link to a page that 404s is worse than no link, and most accounts
        // have written nothing on their first day.
        $this->get(route('public.book', [$this->tenant, $hold]))
            ->assertOk()
            ->assertSee('I agree to the terms and the cancellation policy.')
            ->assertDontSee(route('public.legal', [$this->tenant, 'terms']), false);

        $this->get(route('public.legal', [$this->tenant, 'terms']))->assertNotFound();
    }

    #[Test]
    public function the_booking_keeps_the_policy_its_guest_was_shown(): void
    {
        $this->buildASpot();

        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'Cancel at least 24 hours before and we refund in full.',
        ])->assertRedirect();

        $reservation = $this->bookASpot();

        // Then the venue gets stricter, three weeks later.
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'No refunds at all, for any reason, ever. New policy.',
        ])->assertRedirect();

        /*
         * The confirmation this guest is holding must still say what they
         * agreed to. Before the snapshot, `Voucher::cancellationPolicy()`
         * read the live text while its docblock claimed otherwise — so a
         * venue editing its policy in August rewrote every confirmation it
         * had sent in July.
         */
        $voucher = $this->asTenant(static fn (): Voucher => Voucher::for(
            Reservation::query()->firstOrFail()
        ));

        $this->assertStringContainsString('24 hours', $voucher->cancellationPolicy());
        $this->assertStringNotContainsString('No refunds at all', $voucher->cancellationPolicy());

        $this->assertNotNull($reservation->cancellation_policy_snapshot);
    }

    #[Test]
    public function the_booking_records_which_version_of_the_terms_was_agreed_to(): void
    {
        $this->buildASpot();

        $this->patch(route('dashboard.legal.update', 'terms'), [
            'content' => '## Booking with us'."\n\n".'The deposit comes off your bill.',
        ])->assertRedirect();

        $terms = $this->asTenant(static fn (): ?LegalDocument => LegalDocument::current(
            VenueDocumentType::Terms
        ));

        $reservation = $this->bookASpot();

        $this->assertNotNull($terms);
        $this->assertSame((int) $terms->getKey(), (int) $reservation->terms_version_id);
        $this->assertNotNull($reservation->terms_accepted_at);
        $this->assertNotNull($reservation->terms_accepted_ip);
    }

    #[Test]
    public function a_telephone_booking_snapshots_the_policy_but_agrees_to_nothing(): void
    {
        $this->buildASpot();

        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'Cancel at least 24 hours before and we refund in full.',
        ])->assertRedirect();

        $resource = $this->asTenant(static fn (): Resource => Resource::query()->firstOrFail());

        $this->post(route('dashboard.bookings.store'), [
            'resource' => $resource->id,
            'starts_at' => $this->athens('11:00')->toIso8601String(),
            'people' => 2,
            'name' => 'Telephone Guest',
            'calling_code' => '+30',
            'phone' => '6941234567',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation = $this->asTenant(static fn (): Reservation => Reservation::query()->firstOrFail());

        // The guest gets the same confirmation as anybody else, so the policy
        // is printed on it and has to be snapshotted.
        $this->assertStringContainsString('24 hours', (string) $reservation->cancellation_policy_snapshot);

        /*
         * But nobody ticked a box over the telephone. Recording otherwise
         * would make the one piece of evidence that counts in a dispute
         * worthless everywhere else (§13.2.1).
         */
        $this->assertNull($reservation->terms_accepted_at);
        $this->assertNull($reservation->terms_version_id);
    }

    #[Test]
    public function the_public_page_resolves_its_own_document_and_not_the_tenant(): void
    {
        $this->patch(route('dashboard.legal.update', 'terms'), [
            'content' => '## Booking with us'."\n\n".'The deposit comes off your bill.',
        ])->assertRedirect();

        /*
         * Laravel hands a controller's class-typed arguments by name and
         * everything else **positionally**. This route sits under
         * `reservation/{tenant}`, so a method declared as `(string $type)`
         * receives the *Tenant*, stringified — and the page then answers an
         * ordinary 404 that looks exactly like a missing document. It did,
         * for both pages, until the tenant was put in the signature.
         */
        $this->get(route('public.legal', [$this->tenant, 'terms']))
            ->assertOk()
            ->assertSee('The deposit comes off your bill.')
            ->assertSee('Terms of Booking');
    }

    #[Test]
    public function the_documents_stay_readable_after_the_subscription_lapses(): void
    {
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'Cancel at least 24 hours before and we refund in full.',
        ])->assertRedirect();

        $this->tenant->forceFill([
            'status' => TenantStatus::Expired,
            'trial_ends_at' => now()->subMonth(),
        ])->save();

        auth()->logout();
        app(TenantContext::class)->forget();

        // Guests are still holding confirmations that link here, and a term
        // somebody cannot go back and read is a term that does not hold.
        $this->get(route('public.legal', [$this->tenant, 'cancellation-policy']))
            ->assertOk()
            ->assertSee('Cancel at least 24 hours before');
    }

    #[Test]
    public function another_venue_cannot_read_or_write_these_documents(): void
    {
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'Our own policy, nobody else may see this one.',
        ])->assertRedirect();

        $ours = $this->tenant;

        $this->signInAsOwner('Another Place');

        // The other venue's screen shows the suggested default, not ours.
        $this->get(route('dashboard.legal.edit'))
            ->assertOk()
            ->assertDontSee('nobody else may see this one');

        $this->assertNull($this->asTenant(static fn (): ?LegalDocument => LegalDocument::current(
            VenueDocumentType::CancellationPolicy
        )));

        // And the public page of the first venue is still the first venue's.
        $this->get(route('public.legal', [$ours, 'cancellation-policy']))
            ->assertOk()
            ->assertSee('nobody else may see this one');
    }

    #[Test]
    public function the_public_pages_are_kept_out_of_search_results(): void
    {
        $this->patch(route('dashboard.legal.update', 'cancellation-policy'), [
            'content' => 'Cancel at least 24 hours before and we refund in full.',
        ])->assertRedirect();

        /*
         * Four hundred near-identical cancellation policies are exactly the
         * thin content that gets a whole domain penalised, and the page worth
         * indexing is the booking page (§33).
         */
        $this->get(route('public.legal', [$this->tenant, 'cancellation-policy']))
            ->assertOk()
            ->assertSee('noindex', false);
    }

    /**
     * A sunbed type with one row of spots, enough to take a booking.
     */
    private function buildASpot(): void
    {
        $this->asTenant(function (): void {
            $type = $this->sunbedType(['name' => 'Sunbeds']);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 2, ['base_price_cents' => 0]);
        });
    }

    /**
     * Holds a spot as a guest would.
     *
     * Signs out first: a guest's request resolves the tenant from the URL and
     * leaves nothing bound, and a test that holds while signed in as the
     * owner is not testing the path a tourist takes.
     */
    private function holdASpot(): ReservationHold
    {
        auth()->logout();
        app(TenantContext::class)->forget();

        $resource = $this->asTenant(static fn (): Resource => Resource::query()->firstOrFail());

        $this->post(route('public.hold', $this->tenant), [
            'resource' => $resource->id,
            'starts_at' => $this->athens('11:00')->toIso8601String(),
            'people' => 2,
        ]);

        return $this->asTenant(static fn (): ReservationHold => ReservationHold::query()
            ->orderByDesc('id')
            ->firstOrFail());
    }

    private function bookASpot(): Reservation
    {
        $hold = $this->holdASpot();

        $this->post(route('public.book.store', [$this->tenant, $hold]), [
            'name' => 'Anna Muller',
            'email' => 'anna@example.test',
            'calling_code' => '+49',
            'phone' => '015123456789',
            'terms' => '1',
        ])->assertRedirect();

        return $this->asTenant(static fn (): Reservation => Reservation::query()
            ->orderByDesc('id')
            ->firstOrFail());
    }

    private function athens(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-04 '.$time, 'Europe/Athens')->utc();
    }
}
