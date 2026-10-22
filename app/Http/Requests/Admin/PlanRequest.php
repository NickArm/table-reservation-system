<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BillingPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Writing or changing a plan (SPEC §4.1).
 */
final class PlanRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:80'],

            // Unique because it is how the seeder and the code find a plan
            // (`where('slug', 'monthly')`), not because anybody sees it.
            'slug' => [
                'nullable', 'string', 'max:80',
                Rule::unique('plans', 'slug')->ignore($plan),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],

            // In euros on the form, cents in the database. Zero is allowed:
            // a permanent free tier is a plan row like any other (§4.1).
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],

            /*
             * The provider ids are free text and deliberately not validated
             * against a pattern. Stripe and PayPal have changed their id
             * formats before, and a regex here would reject a perfectly good
             * id with no way round it.
             */
            'stripe_price_id' => ['nullable', 'string', 'max:120'],
            'paypal_plan_id' => ['nullable', 'string', 'max:120'],

            'limits' => ['array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'features' => ['array'],

            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],

            'custom_for_tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }
}
