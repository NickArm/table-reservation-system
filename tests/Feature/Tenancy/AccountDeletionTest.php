<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Tenants\PurgeTenant;
use App\Actions\Tenants\RequestAccountDeletion;
use App\Enums\AuditAction;
use App\Enums\ReservationStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Mail\AccountDeletionRequested;
use App\Models\AuditEntry;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservedSlug;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Deleting an account (SPEC §29).
 *
 * The two things worth testing hardest are the two that cannot be undone: a
 * venue must not be able to vanish leaving guests holding paid bookings, and
 * a purged slug must never become available again — an old QR code on a sign
 * pointing a tourist at a stranger's booking page is the failure this guards
 * against.
 */
final class AccountDeletionTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        Mail::fake();

        $this->signInAsOwner('Crystal Seaside');
    }

    #[Test]
    public function the_screen_says_what_will_go_in_numbers(): void
    {
        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 4);
        });

        // "This permanently deletes everything" means nothing; a number is a
        // sentence somebody stops at (§29.2).
        $this->get(route('dashboard.settings'))
            ->assertOk()
            ->assertSee('tables and sunbeds')
            ->assertSee('Type')
            ->assertSee('Crystal Seaside');
    }

    #[Test]
    public function an_account_with_upcoming_paid_bookings_cannot_be_deleted(): void
    {
        $this->bookingInTheFuture();

        $this->get(route('dashboard.settings'))
            ->assertOk()
            ->assertSee('You have 1 upcoming')
            ->assertDontSee('Type Crystal Seaside to confirm');

        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect()->assertSessionHas('error');

        /*
         * The venue must not be able to vanish leaving tourists with paid
         * bookings that exist nowhere. Same reasoning as a closure (§24.5):
         * the system never cancels a paid booking by itself, and does not let
         * one be orphaned either.
         */
        $this->assertNull($this->tenant->refresh()->deletion_requested_at);
    }

    #[Test]
    public function a_booking_that_has_already_happened_is_not_a_blocker(): void
    {
        $this->bookingInThePast();

        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($this->tenant->refresh()->deletion_requested_at);
    }

    #[Test]
    public function the_venue_name_has_to_be_typed_out(): void
    {
        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'crystal seaside',
            'password' => 'correct-horse-battery',
        ])->assertSessionHasErrors('confirm');

        // Typing is the only confirmation that cannot be clicked through by
        // muscle memory, and this is the one action with no undo inside the
        // product.
        $this->assertNull($this->tenant->refresh()->deletion_requested_at);
    }

    #[Test]
    public function the_password_is_asked_for_too(): void
    {
        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('password');

        // The screen is reachable from a laptop somebody left open.
        $this->assertNull($this->tenant->refresh()->deletion_requested_at);
    }

    #[Test]
    public function staff_cannot_end_the_business(): void
    {
        $staff = User::create([
            'name' => 'Floor Staff',
            'email' => 'staff@example.test',
            'password' => 'correct-horse-battery',
        ]);

        $staff->forceFill(['email_verified_at' => now()])->save();

        $this->tenant->users()->attach($staff, ['role' => TenantRole::Staff->value]);

        $this->actingAs($staff->refresh());

        // Staff run the floor (§2.3).
        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertForbidden();

        $this->assertNull($this->tenant->refresh()->deletion_requested_at);
    }

    #[Test]
    public function requesting_it_stops_the_bookings_sets_a_date_and_emails_everybody(): void
    {
        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('home'));

        $tenant = $this->tenant->refresh();

        $this->assertNotNull($tenant->deletion_requested_at);
        $this->assertSame(
            now()->addDays((int) config('tenancy.purge_after_days'))->format('Y-m-d'),
            $tenant->purge_at?->format('Y-m-d'),
        );

        /*
         * Suspended rather than expired, and that is the load-bearing part:
         * `SyncTenantStatus` never undoes a suspension, so a stray webhook
         * cannot bring a deleted account back to life (§4.3).
         */
        $this->assertSame(TenantStatus::Suspended, $tenant->status);

        // Signed out: a read-only dashboard that no longer takes bookings is
        // a worse full stop than the plain one.
        $this->assertGuest();

        Mail::assertSent(AccountDeletionRequested::class, function (AccountDeletionRequested $mail): bool {
            $mail->assertTo($this->owner->email);

            return $mail->purgeAt !== null;
        });

        $this->assertTrue(
            AuditEntry::query()->where('action', AuditAction::AccountDeletionRequested)->exists()
        );
    }

    #[Test]
    public function nothing_is_actually_deleted_for_thirty_days(): void
    {
        $this->bookingInThePast();

        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect();

        // Twenty-nine days later the sweep leaves it alone.
        $this->travel(29)->days();

        $this->artisan('tenants:purge')->assertSuccessful();

        $this->assertNotNull(Tenant::withTrashed()->find($this->tenant->getKey()));
        $this->assertSame(1, Reservation::query()->acrossTenants()->count());
    }

    #[Test]
    public function after_the_wait_everything_goes_and_the_slug_is_reserved_for_ever(): void
    {
        $this->bookingInThePast();

        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect();

        $this->travel(31)->days();

        $this->artisan('tenants:purge')->assertSuccessful();

        // One forceDelete, and every tenant_id foreign key cascades.
        $this->assertNull(Tenant::withTrashed()->find($this->tenant->getKey()));
        $this->assertSame(0, Reservation::query()->acrossTenants()->count());
        $this->assertSame(0, Guest::query()->acrossTenants()->count());
        $this->assertSame(0, Resource::query()->acrossTenants()->count());

        /*
         * The slug outlives the account. An old link or a printed QR code
         * must never resolve to a different venue (§29.3).
         */
        $this->assertTrue(ReservedSlug::isTaken('crystal-seaside'));

        // And the platform's record of the deletion outlives it too.
        $entry = AuditEntry::query()
            ->where('action', AuditAction::AccountPurged)
            ->firstOrFail();

        $this->assertSame('Crystal Seaside', $entry->affected_tenant_name);
        $this->assertNull($entry->affected_tenant_id);
    }

    #[Test]
    public function the_reserved_slug_is_not_handed_to_the_next_venue_of_the_same_name(): void
    {
        ReservedSlug::reserve('crystal-seaside', 'Crystal Seaside');

        auth()->logout();
        app(TenantContext::class)->forget();

        $this->post(route('register'), [
            'venue_name' => 'Crystal Seaside',
            'name' => 'Somebody Else',
            'email' => 'else@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => '1',
        ])->assertRedirect();

        $fresh = Tenant::query()->where('name', 'Crystal Seaside')->orderByDesc('id')->firstOrFail();

        $this->assertNotSame('crystal-seaside', $fresh->slug);
    }

    #[Test]
    public function an_account_that_never_asked_is_never_purged(): void
    {
        $this->tenant->forceFill([
            'status' => TenantStatus::Expired,
            'trial_ends_at' => now()->subYears(3),
        ])->save();

        $this->travel(400)->days();

        // The twelve-month retention sweep is a different thing, and it does
        // not live in this command (§4.4).
        $this->artisan('tenants:purge')->assertSuccessful();

        $this->assertNotNull(Tenant::withTrashed()->find($this->tenant->getKey()));
    }

    #[Test]
    public function the_dry_run_deletes_nothing(): void
    {
        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect();

        $this->travel(31)->days();

        $this->artisan('tenants:purge --dry-run')
            ->expectsOutputToContain('Crystal Seaside')
            ->assertSuccessful();

        $this->assertNotNull(Tenant::withTrashed()->find($this->tenant->getKey()));
    }

    #[Test]
    public function support_can_put_a_deletion_back_with_a_reason(): void
    {
        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect();

        $admin = $this->signInAsPlatformAdmin();

        app(TenantContext::class)->withoutScoping(fn () => app(RequestAccountDeletion::class)->cancel(
            $this->tenant->refresh(),
            $admin,
            'Rang up the next morning and asked us to stop it.',
        ));

        $tenant = $this->tenant->refresh();

        $this->assertNull($tenant->deletion_requested_at);
        $this->assertNull($tenant->purge_at);

        $this->travel(40)->days();

        $this->artisan('tenants:purge')->assertSuccessful();

        $this->assertNotNull(Tenant::withTrashed()->find($tenant->getKey()));

        $this->assertTrue(
            AuditEntry::query()->where('action', AuditAction::AccountDeletionCancelled)->exists()
        );
    }

    #[Test]
    public function the_export_is_complete_and_leaves_out_the_payment_keys(): void
    {
        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 2);

            \App\Models\PaymentGatewaySetting::factory()->stripe()->create();
        });

        $response = $this->get(route('dashboard.account.export'))->assertOk();

        $payload = json_decode($response->content(), true);

        $this->assertIsArray($payload);
        $this->assertSame('Crystal Seaside', $payload['account']['name']);
        $this->assertCount(2, $payload['resources']);
        $this->assertArrayHasKey('guests', $payload);

        /*
         * The venue's own gateway keys are encrypted at rest and no endpoint
         * has ever returned them (§7.4). A download that dropped them into
         * somebody's Downloads folder would undo that in one click.
         */
        $this->assertStringNotContainsString('sk_test', (string) $response->content());
        $this->assertArrayNotHasKey('payment_gateway_settings', $payload);
    }

    #[Test]
    public function the_export_still_works_once_the_subscription_has_run_out(): void
    {
        $this->tenant->forceFill([
            'status' => TenantStatus::Expired,
            'trial_ends_at' => now()->subMonth(),
        ])->save();

        // Promised twice in the spec (§4.4, §29.2). An export that stops
        // working when a subscription lapses is missing exactly when needed.
        $this->get(route('dashboard.account.export'))->assertOk();
        $this->get(route('dashboard.settings'))->assertOk();
    }

    #[Test]
    public function another_account_is_untouched_by_a_purge(): void
    {
        $this->bookingInThePast();

        $this->post(route('dashboard.account.destroy'), [
            'confirm' => 'Crystal Seaside',
            'password' => 'correct-horse-battery',
        ])->assertRedirect();

        $this->signInAsOwner('Another Place');

        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'B', 3);
        });

        $survivor = $this->tenant;

        $this->travel(31)->days();

        app(PurgeTenant::class);
        $this->artisan('tenants:purge')->assertSuccessful();

        $this->assertNotNull(Tenant::withTrashed()->find($survivor->getKey()));

        $this->assertSame(3, app(TenantContext::class)->asTenant(
            $survivor,
            static fn (): int => Resource::query()->count(),
        ));
    }

    private function bookingInTheFuture(): void
    {
        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $resource = $this->resourcesIn($area, 'A', 1)->first();

            Reservation::factory()->create([
                'resource_id' => $resource->id,
                'booking_type_id' => $type->id,
                'area_id' => $area->id,
                'status' => ReservationStatus::Confirmed,
                'starts_at' => now()->addWeek(),
                'ends_at' => now()->addWeek()->addHours(4),
            ]);
        });
    }

    private function bookingInThePast(): void
    {
        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $resource = $this->resourcesIn($area, 'A', 1)->first();

            Reservation::factory()->create([
                'resource_id' => $resource->id,
                'booking_type_id' => $type->id,
                'area_id' => $area->id,
                'status' => ReservationStatus::Completed,
                'starts_at' => now()->subWeek(),
                'ends_at' => now()->subWeek()->addHours(4),
            ]);
        });
    }
}
