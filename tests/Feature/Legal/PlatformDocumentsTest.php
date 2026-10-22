<?php

declare(strict_types=1);

namespace Tests\Feature\Legal;

use App\Enums\PlatformDocumentType;
use App\Enums\TenantStatus;
use App\Models\PlatformDocument;
use App\Models\Tenant;
use Database\Seeders\PlatformDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The platform's legal pages (SPEC §12.2).
 *
 * The requirement is that the texts arrive **without a deploy**, so most of
 * what is tested here is the versioning: a published version is frozen,
 * because an account agreed to it by name and the words behind that name must
 * not be able to change afterwards.
 */
final class PlatformDocumentsTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function all_four_pages_are_public_and_reachable_without_an_account(): void
    {
        $this->seed(PlatformDocumentSeeder::class);

        foreach (PlatformDocumentType::cases() as $type) {
            // Without an account, because these are the pages somebody reads
            // *before* signing up.
            $this->get(route('legal', $type->path()))
                ->assertOk()
                ->assertSee($type->title());
        }

        /*
         * And the Markdown actually renders. The marker that makes the draft
         * notice appear is an HTML comment, so it must not survive into the
         * page — `html_input => 'strip'` is what keeps a document pasted out
         * of Word from bringing half a stylesheet with it.
         */
        $this->get(route('legal', 'terms'))
            ->assertOk()
            ->assertSee('What this service is')
            ->assertSee('booking software')
            ->assertDontSee('placeholder-->', false)
            ->assertDontSee('## What this service is');
    }

    #[Test]
    public function the_placeholder_says_loudly_that_it_is_not_final(): void
    {
        $this->seed(PlatformDocumentSeeder::class);

        /*
         * The danger with placeholder legal text is not that it is missing —
         * it is that it reads like the real thing and somebody relies on it.
         */
        $this->get(route('legal', 'terms'))
            ->assertOk()
            ->assertSee('Draft — pending legal review', false)
            ->assertSee('not the final wording');
    }

    #[Test]
    public function a_page_with_nothing_published_still_renders(): void
    {
        // A 404 on a link printed in the sign-up form reads as a broken
        // product rather than an unfinished text.
        $this->get(route('legal', 'dpa'))
            ->assertOk()
            ->assertSee('Not published yet');
    }

    #[Test]
    public function an_unpublished_draft_is_never_served(): void
    {
        PlatformDocument::factory()->draft()->create([
            'content' => 'Half-written nonsense that must not be public.',
        ]);

        $this->get(route('legal', 'terms'))
            ->assertOk()
            ->assertDontSee('Half-written nonsense')
            ->assertSee('Not published yet');
    }

    #[Test]
    public function the_legal_pages_are_indexable_while_the_rest_of_the_app_is_not(): void
    {
        $this->seed(PlatformDocumentSeeder::class);

        // A `noindex` on the terms page makes a product look like it is
        // hiding something; everywhere behind a login is the opposite.
        $this->get(route('legal', 'terms'))
            ->assertOk()
            ->assertDontSee('noindex');

        $this->signInAsOwner();

        $this->get(route('dashboard.today'))->assertOk()->assertSee('noindex', false);
    }

    #[Test]
    public function an_unknown_document_is_not_found(): void
    {
        $this->get('/refund-policy')->assertNotFound();
    }

    #[Test]
    public function registering_without_ticking_the_box_is_refused(): void
    {
        $this->post(route('register'), [
            'venue_name' => 'New Place',
            'name' => 'Owner',
            'email' => 'new@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertSessionHasErrors('terms');

        $this->assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function registering_records_which_version_was_agreed_to_when_and_from_where(): void
    {
        $this->seed(PlatformDocumentSeeder::class);

        $this->post(route('register'), [
            'venue_name' => 'New Place',
            'name' => 'Owner',
            'email' => 'new@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => '1',
        ])->assertRedirect();

        $tenant = Tenant::query()->where('name', 'New Place')->firstOrFail();

        // All three matter in a dispute, and the version only means anything
        // because a published version can never be edited.
        $this->assertSame('0.1-draft', $tenant->accepted_terms_version);
        $this->assertNotNull($tenant->accepted_terms_at);
        $this->assertSame('127.0.0.1', $tenant->accepted_terms_ip);
    }

    #[Test]
    public function a_published_version_cannot_be_edited(): void
    {
        $this->signInAsPlatformAdmin();

        PlatformDocument::factory()->create([
            'version' => '1.0',
            'content' => 'The words they agreed to.',
        ]);

        $this->patch(route('admin.legal.update', 'terms'), [
            'version' => '1.0',
            'content' => 'Different words entirely.',
            'publish' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        /*
         * The point of the whole table. An account agreed to "1.0"; letting
         * 1.0 be rewritten would silently change what they agreed to.
         */
        $this->assertSame(
            'The words they agreed to.',
            PlatformDocument::query()->where('version', '1.0')->value('content'),
        );
    }

    #[Test]
    public function publishing_a_new_version_supersedes_the_old_one_and_keeps_it(): void
    {
        $this->signInAsPlatformAdmin();

        $first = PlatformDocument::factory()->create(['version' => '1.0']);

        $this->patch(route('admin.legal.update', 'terms'), [
            'version' => '2.0',
            'content' => '## Terms'."\n\n".'The new wording.',
            'change_summary' => 'Added a clause about seasonal suspension',
            'needs_reacceptance' => '1',
            'publish' => '1',
        ])->assertRedirect(route('admin.legal.index'))->assertSessionHasNoErrors();

        $this->assertFalse((bool) $first->refresh()->is_current);
        $this->assertNotNull($first->published_at);

        $current = PlatformDocument::current(PlatformDocumentType::Terms);

        $this->assertNotNull($current);
        $this->assertSame('2.0', $current->version);

        $this->get(route('legal', 'terms'))->assertOk()->assertSee('The new wording.');
    }

    #[Test]
    public function a_draft_can_be_saved_without_going_live(): void
    {
        $this->signInAsPlatformAdmin();

        PlatformDocument::factory()->create(['version' => '1.0', 'content' => 'Live text.']);

        $this->patch(route('admin.legal.update', 'terms'), [
            'version' => '2.0-draft',
            'content' => 'Not ready yet.',
            'publish' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // The lawyer's text arrives in pieces and gets read twice before it
        // goes live.
        $this->get(route('legal', 'terms'))
            ->assertOk()
            ->assertSee('Live text.')
            ->assertDontSee('Not ready yet.');

        // And reopening the editor offers the draft, not the live version.
        $this->get(route('admin.legal.edit', 'terms'))
            ->assertOk()
            ->assertSee('Not ready yet.')
            ->assertSee('unpublished draft');
    }

    #[Test]
    public function a_change_that_needs_agreeing_to_puts_a_banner_up_until_they_agree(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(fn () => $this->tenant->forceFill([
            'accepted_terms_version' => '1.0',
            'accepted_terms_at' => now()->subYear(),
        ])->save());

        PlatformDocument::factory()->superseded()->create(['version' => '1.0']);
        PlatformDocument::factory()->create([
            'version' => '2.0',
            'needs_reacceptance' => true,
            'change_summary' => 'Added a clause about seasonal suspension',
        ]);

        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('have changed')
            ->assertSee('seasonal suspension');

        $this->post(route('dashboard.terms.accept'))->assertRedirect();

        $this->assertSame('2.0', $this->tenant->refresh()->accepted_terms_version);

        $this->get(route('dashboard.today'))->assertOk()->assertDontSee('have changed');
    }

    #[Test]
    public function a_version_that_does_not_ask_for_agreement_puts_no_banner_up(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(fn () => $this->tenant
            ->forceFill(['accepted_terms_version' => '1.0'])
            ->save());

        // A typo fix must not interrupt four hundred venues. A banner that
        // appears for every edit is one nobody reads by the third time.
        PlatformDocument::factory()->create(['version' => '1.1']);

        $this->get(route('dashboard.today'))->assertOk()->assertDontSee('have changed');
    }

    #[Test]
    public function an_account_that_never_agreed_to_anything_is_not_chased(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        // Registered before anything was published. The banner is for a
        // change, not for a gap — the gap closes on the next publish.
        PlatformDocument::factory()->create([
            'version' => '2.0',
            'needs_reacceptance' => true,
        ]);

        $this->get(route('dashboard.today'))->assertOk()->assertDontSee('have changed');
    }

    #[Test]
    public function an_expired_account_can_still_agree_to_the_new_terms(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->tenant->forceFill([
            'status' => TenantStatus::Expired,
            'trial_ends_at' => now()->subMonth(),
            'accepted_terms_version' => '1.0',
        ])->save();

        PlatformDocument::factory()->create([
            'version' => '2.0',
            'needs_reacceptance' => true,
        ]);

        // The read-only lock has to let this through: the banner is shown to
        // an expired account too, and refusing its one button would be absurd.
        $this->post(route('dashboard.terms.accept'))->assertRedirect();

        $this->assertSame('2.0', $this->tenant->refresh()->accepted_terms_version);
    }

    #[Test]
    public function a_venue_owner_cannot_edit_the_platforms_legal_texts(): void
    {
        $this->signInAsOwner();

        $this->get(route('admin.legal.index'))->assertNotFound();
        $this->get(route('admin.legal.edit', 'terms'))->assertNotFound();
        $this->patch(route('admin.legal.update', 'terms'), [
            'version' => '9.9',
            'content' => 'We owe the venue a million euros.',
        ])->assertNotFound();
    }

    #[Test]
    public function the_seeder_leaves_a_real_published_text_alone(): void
    {
        PlatformDocument::factory()->create([
            'version' => '1.0',
            'content' => "## Terms\n\nWritten by an actual lawyer.",
        ]);

        // A deploy runs the seeders. One that overwrote the real wording with
        // a placeholder would be a way to lose a contract quietly.
        $this->seed(PlatformDocumentSeeder::class);

        $current = PlatformDocument::current(PlatformDocumentType::Terms);

        $this->assertNotNull($current);
        $this->assertSame('1.0', $current->version);
        $this->assertStringContainsString('actual lawyer', $current->content);
    }
}
