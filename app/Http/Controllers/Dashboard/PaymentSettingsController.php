<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Http\Controllers\Controller;
use App\Models\PaymentGatewaySetting;
use App\Models\Venue;
use App\Payments\GatewayManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Where a venue puts its own payment keys (SPEC §7.4, §24.3).
 *
 * Keys are never sent back to the browser, only masked. An owner who cannot
 * remember which key is in there can tell from the last four characters; an
 * attacker with a stolen session cannot read them at all.
 */
final class PaymentSettingsController extends Controller
{
    public function edit(TenantContext $context, GatewayManager $gateways): View
    {
        $tenant = $context->getOrFail();

        $settings = PaymentGatewaySetting::query()
            ->get()
            ->keyBy(static fn (PaymentGatewaySetting $setting): string => $setting->gateway->value);

        return view('dashboard.payments', [
            'tenant' => $tenant,
            'venue' => Venue::query()->first(),
            'settings' => $settings,
            'gateways' => Gateway::cases(),
            'active' => $gateways->settingsFor(),
            'webhookUrls' => [
                Gateway::Stripe->value => route('webhooks', [Gateway::Stripe->value, $tenant->uuid]),
                Gateway::PayPal->value => route('webhooks', [Gateway::PayPal->value, $tenant->uuid]),
            ],
        ]);
    }

    public function update(Request $request, Gateway $gateway): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::enum(GatewayMode::class)],
            'statement_descriptor' => ['nullable', 'string', 'max:22'],
            'is_active' => ['nullable', 'boolean'],

            'secret_key' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'webhook_id' => ['nullable', 'string', 'max:255'],
        ]);

        $setting = PaymentGatewaySetting::query()->firstOrNew(['gateway' => $gateway->value]);

        $setting->fill([
            'gateway' => $gateway,
            'mode' => GatewayMode::from($validated['mode']),
            'statement_descriptor' => $validated['statement_descriptor'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        // Only overwrite a key the owner actually typed: an empty box means
        // "leave it alone", not "delete it".
        $credentials = $setting->credentials ?? [];

        foreach (['secret_key', 'webhook_secret', 'client_id', 'client_secret', 'webhook_id'] as $key) {
            if (filled($validated[$key] ?? null)) {
                $credentials[$key] = trim((string) $validated[$key]);
            }
        }

        $setting->setCredentials($credentials);
        $setting->save();

        if ($setting->is_active) {
            $this->deactivateOthers($gateway);
        }

        return back()->with('status', "{$gateway->label()} saved.");
    }

    /**
     * A real round trip to the gateway, so nobody discovers a wrong key
     * through a guest's failed booking (SPEC §24.3).
     */
    public function test(Gateway $gateway, GatewayManager $gateways): RedirectResponse
    {
        $setting = PaymentGatewaySetting::query()->where('gateway', $gateway->value)->firstOrFail();

        try {
            $result = $gateways->for($gateway)->testConnection($setting);
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }

        $setting->forceFill([
            'last_tested_at' => now(),
            'last_test_result' => $result['ok'] ? 'ok' : $result['message'],
        ])->save();

        return $result['ok']
            ? back()->with('status', $result['message'])
            : back()->withErrors(['gateway' => $result['message']]);
    }

    /**
     * One gateway at a time: a guest offered three payment buttons is a guest
     * who closes the tab (§1.1).
     */
    private function deactivateOthers(Gateway $gateway): void
    {
        PaymentGatewaySetting::query()
            ->where('gateway', '!=', $gateway->value)
            ->update(['is_active' => false]);
    }
}
