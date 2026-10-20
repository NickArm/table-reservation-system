<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Enums\Feature;
use App\Enums\PlanLimit;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plans the platform sells (SPEC §4.1).
 *
 * Two of them, and the yearly one is priced to be chosen: €500 works out at
 * about €41.70 a month against €70, which is a large enough gap to be worth a
 * year's commitment — better cash flow for us and less churn.
 *
 * Both are unlimited. Against the competition that is the easy part: €70 for
 * unlimited undercuts resOS at €125 and Tableo at about €96. The limits exist
 * in the schema for the custom plans a super admin writes by hand, and for
 * whatever entry-level tier we decide on — not to nickel-and-dime a taverna
 * that had a good August.
 *
 * Idempotent, so a deploy can run it again without duplicating anything or
 * overwriting a price somebody changed on purpose.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $unlimited = [
            PlanLimit::Venues->value => 1,
            PlanLimit::BookingTypes->value => null,
            PlanLimit::Resources->value => null,
            PlanLimit::ReservationsPerMonth->value => null,
            PlanLimit::StaffUsers->value => null,
        ];

        $everything = array_fill_keys(
            array_map(static fn (Feature $feature): string => $feature->value, Feature::cases()),
            true,
        );

        // One venue each, so multi_venue is the one thing neither public plan
        // switches on. An account that needs a second venue is a conversation
        // and a custom plan, not a checkbox.
        $everything[Feature::MultiVenue->value] = false;
        $everything[Feature::CustomDomain->value] = false;

        // Nothing sends a text message yet (SPEC §10), so advertising it on a
        // plan would be selling something that does not exist.
        $everything[Feature::SmsNotifications->value] = false;

        $this->plan([
            'slug' => 'monthly',
            'name' => 'Monthly',
            'description' => 'Everything, month by month. Cancel whenever you like — '
                .'your bookings keep working until the month you paid for runs out.',
            'billing_period' => BillingPeriod::Monthly,
            'price_cents' => 7000,
            'sort_order' => 10,
            'limits' => $unlimited,
            'features' => $everything,
        ]);

        $this->plan([
            'slug' => 'annual',
            'name' => 'Annual',
            'description' => 'The same thing for a year, at about €41.70 a month. '
                .'Best for a venue that already knows it wants the season.',
            'billing_period' => BillingPeriod::Yearly,
            'price_cents' => 50000,
            'sort_order' => 20,
            'limits' => $unlimited,
            'features' => $everything,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function plan(array $attributes): void
    {
        $slug = $attributes['slug'];

        unset($attributes['slug']);

        // Created if missing, left alone if there. A seeder that overwrote a
        // live price on every deploy would be a way to lose money quietly.
        Plan::query()->firstOrCreate(
            ['slug' => $slug],
            [...$attributes, 'currency' => 'EUR', 'trial_days' => (int) config('tenancy.trial_days')],
        );
    }
}
