<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Models\PaymentGatewaySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * A venue's own keys (SPEC §7.4, §24.3).
 */
final class PaymentSettingsTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner();
    }

    #[Test]
    public function save_and_test_is_one_press_through_the_same_form(): void
    {
        // Exactly the request the browser sends: the settings form's own
        // PATCH, with the test button's name and value alongside the fields.
        //
        // The previous version of that button used `formaction` to point at
        // a POST-only route, which changes the address and not the method —
        // so pressing it produced a 405 instead of an answer. The endpoint
        // had a test; the button did not, which is why nothing caught it.
        Http::fake(['api.stripe.com/v1/balance' => Http::response(['object' => 'balance'])]);

        $this->patch(route('dashboard.payments.update', Gateway::Stripe->value), [
            'mode' => GatewayMode::Test->value,
            'secret_key' => 'sk_test_abcdefghijklmnop1234',
            'is_active' => '1',
            'test' => '1',
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $setting = $this->asTenant(
            static fn (): PaymentGatewaySetting => PaymentGatewaySetting::query()->firstOrFail()
        );

        // Both halves happened: the key is stored, and it was tried.
        $this->assertSame('sk_test_abcdefghijklmnop1234', $setting->credential('secret_key'));
        $this->assertNotNull($setting->last_tested_at);
        $this->assertSame('ok', $setting->last_test_result);
    }

    #[Test]
    public function a_key_that_does_not_work_is_still_saved_and_the_failure_reported(): void
    {
        Http::fake(['api.stripe.com/v1/balance' => Http::response(
            ['error' => ['message' => 'Invalid API Key provided.']],
            401,
        )]);

        $this->patch(route('dashboard.payments.update', Gateway::Stripe->value), [
            'mode' => GatewayMode::Test->value,
            'secret_key' => 'sk_test_wrong_one_here_1234',
            'test' => '1',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('gateway');

        $setting = $this->asTenant(
            static fn (): PaymentGatewaySetting => PaymentGatewaySetting::query()->firstOrFail()
        );

        // Saving it anyway matters: the owner can correct one character
        // rather than retyping the whole key.
        $this->assertSame('sk_test_wrong_one_here_1234', $setting->credential('secret_key'));
        $this->assertNotSame('ok', $setting->last_test_result);
    }

    #[Test]
    public function a_venue_with_no_gateway_is_told_that_is_fine(): void
    {
        $this->get(route('dashboard.payments.edit'))
            ->assertOk()
            ->assertSee('guests settle with you at the venue');
    }

    #[Test]
    public function keys_are_saved_encrypted_and_never_shown_again(): void
    {
        $this->patch(route('dashboard.payments.update', Gateway::Stripe->value), [
            'mode' => GatewayMode::Test->value,
            'secret_key' => 'sk_test_abcdefghijklmnop1234',
            'webhook_secret' => 'whsec_abcdefghijklmnop',
            'is_active' => '1',
        ])->assertRedirect();

        $setting = $this->asTenant(static fn (): PaymentGatewaySetting => PaymentGatewaySetting::query()->firstOrFail());

        $this->assertSame('sk_test_abcdefghijklmnop1234', $setting->credential('secret_key'));

        // On disk it is ciphertext, not a key anybody can read.
        $raw = (string) $this->asTenant(static fn () => \Illuminate\Support\Facades\DB::table('payment_gateway_settings')
            ->where('id', $setting->id)
            ->value('credentials'));

        $this->assertStringNotContainsString('sk_test_abcdefghijklmnop1234', $raw);

        // And the screen shows only enough to recognise it.
        $this->get(route('dashboard.payments.edit'))
            ->assertOk()
            ->assertDontSee('sk_test_abcdefghijklmnop1234')
            ->assertSee('sk_test••••1234');
    }

    #[Test]
    public function an_empty_box_means_leave_the_key_alone(): void
    {
        $this->patch(route('dashboard.payments.update', Gateway::Stripe->value), [
            'mode' => GatewayMode::Test->value,
            'secret_key' => 'sk_test_original_key_000000',
        ]);

        $this->patch(route('dashboard.payments.update', Gateway::Stripe->value), [
            'mode' => GatewayMode::Live->value,
            'secret_key' => '',
        ]);

        $setting = $this->asTenant(static fn (): PaymentGatewaySetting => PaymentGatewaySetting::query()->firstOrFail());

        $this->assertSame('sk_test_original_key_000000', $setting->credential('secret_key'));
        $this->assertSame(GatewayMode::Live, $setting->mode);
    }

    #[Test]
    public function only_one_gateway_is_in_use_at_a_time(): void
    {
        $this->patch(route('dashboard.payments.update', Gateway::CashOnArrival->value), [
            'mode' => GatewayMode::Live->value,
            'is_active' => '1',
        ]);

        $this->patch(route('dashboard.payments.update', Gateway::Stripe->value), [
            'mode' => GatewayMode::Test->value,
            'secret_key' => 'sk_test_key_0000000000',
            'is_active' => '1',
        ]);

        $active = $this->asTenant(static fn () => PaymentGatewaySetting::query()
            ->where('is_active', true)
            ->pluck('gateway'));

        $this->assertCount(1, $active);
        $this->assertSame(Gateway::Stripe, $active->first());
    }

    #[Test]
    public function the_test_button_says_plainly_whether_the_keys_work(): void
    {
        $this->asTenant(fn () => PaymentGatewaySetting::factory()->stripe()->create());

        // One sequence rather than two `Http::fake()` calls: a second fake for
        // the same address does not replace the first, it is appended, and the
        // first stub still answers — so the second request got the success
        // response again and the failure branch was never exercised.
        Http::fake([
            'api.stripe.com/v1/balance' => Http::sequence()
                ->push(['object' => 'balance'])
                ->push(['error' => ['message' => 'Invalid API Key provided.']], 401),
        ]);

        $this->post(route('dashboard.payments.test', Gateway::Stripe->value))
            ->assertRedirect()
            ->assertSessionHas('status', 'Connected to Stripe.');

        $this->post(route('dashboard.payments.test', Gateway::Stripe->value))
            ->assertRedirect()
            ->assertSessionHasErrors('gateway');
    }

    #[Test]
    public function test_mode_is_impossible_to_miss(): void
    {
        $this->asTenant(function (): void {
            PaymentGatewaySetting::factory()->stripe()->create();

            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 1);
        });

        $this->get(route('dashboard.payments.edit'))
            ->assertOk()
            ->assertSee('Test mode.');

        $this->get(route('public.search', [$this->tenant, 'people' => 2]))
            ->assertOk()
            ->assertSee('no real payments are taken');
    }

    #[Test]
    public function another_venue_cannot_read_or_write_these_keys(): void
    {
        $this->asTenant(fn () => PaymentGatewaySetting::factory()->stripe()->create());

        $this->signInAsOwner('Another Place');

        $this->get(route('dashboard.payments.edit'))
            ->assertOk()
            ->assertDontSee('sk_test');

        $this->post(route('dashboard.payments.test', Gateway::Stripe->value))->assertNotFound();
    }
}
