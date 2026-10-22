<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Closures\CloseBookings;
use App\Actions\Guests\RecordGuest;
use App\Actions\Structure\BulkCreateResources;
use App\Actions\Structure\CreateBookingType;
use App\Actions\Structure\SetResourceAvailability;
use App\Actions\Subscriptions\RecordSubscriptionEvent;
use App\Actions\Subscriptions\SyncTenantStatus;
use App\Enums\ApiTokenMode;
use App\Enums\BillingProvider;
use App\Enums\BookingMode;
use App\Enums\Channel;
use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Enums\Occupancy;
use App\Enums\OnlineStatus;
use App\Enums\PaymentRequirement;
use App\Enums\PaymentStatus;
use App\Enums\PriceBasis;
use App\Enums\PriceRuleType;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Enums\StructureScope;
use App\Enums\SubscriptionEventType;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\ApiToken;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Guest;
use App\Models\PaymentGatewaySetting;
use App\Models\Plan;
use App\Models\PriceRule;
use App\Models\Reservation;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Models\ResourceTemplate;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingArrived;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One complete, realistic venue with every feature filled in.
 *
 * Three jobs (SPEC §22): a demo that sells, a working set of data for
 * development, and a fixture the tests can lean on.
 *
 * It grows with every milestone — a feature that cannot be represented here is
 * a feature that is either too complicated or missing something in the model
 * (SPEC §22.3).
 */
class DemoRestaurantSeeder extends Seeder
{
    private const NAME = 'Demo Beach Bar';

    private const SLUG = 'demo';

    private const OWNER_EMAIL = 'owner@demo.test';

    private const STAFF_EMAIL = 'staff@demo.test';

    private const PASSWORD = 'password';

    /**
     * Deliberately obvious. A token that reads like a real secret invites
     * somebody to wonder whether it is one.
     */
    private const API_TOKEN_BODY = 'demo'.'2222333344445555666677778888999900001111';

    private const MEDIA_DIR = 'venues/demo';

    private const TIMEZONE = 'Europe/Athens';

    private Faker $faker;

    /**
     * The people who book at this venue. A fixed pool rather than a fresh
     * stranger per booking, because the guest list is only worth looking at if
     * somebody in it has come back (SPEC §28).
     *
     * @var list<array{name: string, email: string, phone: string}>
     */
    private array $regulars = [];

    /** @var array<int, Area|null> */
    private array $areaCache = [];

    public function run(): void
    {
        // Seeded, so that re-running produces the same believable venue
        // rather than a different one every time (SPEC §22.2).
        $this->faker = FakerFactory::create();
        $this->faker->seed(20261011);

        if (app()->isProduction()) {
            $this->command?->error('The demo seeder refuses to run in production.');

            return;
        }

        $context = app(TenantContext::class);

        $tenant = $context->withoutScoping(function (): Tenant {
            $this->wipeExistingDemo();

            return Tenant::create([
                'name' => self::NAME,
                'slug' => self::SLUG,
                'status' => TenantStatus::Active,
                'timezone' => 'Europe/Athens',
                'locale' => 'en',
                'currency' => 'EUR',
            ]);
        });

        // forceCreate, because email_verified_at is deliberately not fillable
        // and strict mode refuses to discard it quietly.
        $owner = User::forceCreate([
            'name' => 'Demo Owner',
            'email' => self::OWNER_EMAIL,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ]);

        $staff = User::forceCreate([
            'name' => 'Demo Staff',
            'email' => self::STAFF_EMAIL,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ]);

        $tenant->users()->attach($owner, ['role' => TenantRole::Owner->value]);
        $tenant->users()->attach($staff, ['role' => TenantRole::Staff->value]);

        $context->asTenant($tenant, function () use ($owner, $staff): void {
            $venue = $this->createVenue();

            $this->createSunbeds($venue);
            $this->createTables($venue);
            $this->createPriceRules();
            $this->createPaymentSettings();
            $this->createClosures();
            $this->createReservations($venue);
            $this->fillTheBell($venue, [$owner, $staff]);
            $this->annotateGuests();
            $this->createApiTokens();
            $this->createSubscription();
        });

        $this->report();
    }

    private function createVenue(): Venue
    {
        return Venue::create([
            'name' => self::NAME,
            'description' => 'Hidden in a quiet bay on the north-east coast of Corfu, '
                .'the beach runs straight into a short menu and a long afternoon: clear '
                .'water, shade when you want it, and a few steps from the sand to the '
                ."table.\n\n"
                .'Sunbeds on the beach, tables for dinner, both bookable ahead.',
            'address' => 'Kalami Beach',
            'city' => 'Kalami',
            'postal_code' => '49083',
            'region' => 'Corfu',
            'country' => 'GR',
            'latitude' => 39.7345,
            'longitude' => 19.9298,
            'phone' => '+302663000000',
            'public_email' => 'hello@demobeachbar.test',
            'website_url' => 'https://demobeachbar.test',
            'venue_category' => 'beach_bar',
            'cuisine' => 'Mediterranean',
            'price_range' => '€€',
            'accent_color' => '#0E6E6E',
            'hero_overlay' => 45,
            'logo_path' => $this->publishMedia('demo-logo.png', 'logo.png'),
            'hero_path' => $this->publishMedia('demo-hero.jpg', 'hero.jpg'),
            'timezone' => 'Europe/Athens',

            // Alerts, set the way a real beach bar would have them: a second
            // address for the kitchen, no emails between one and eight in the
            // morning, the day's list at eight, guests reminded the evening
            // before (SPEC §10.2).
            'notify_on_booking' => true,
            'notification_emails' => ['kitchen@demobeachbar.test'],
            'daily_summary_hour' => 8,
            'quiet_hours_from' => '01:00',
            'quiet_hours_to' => '08:00',
            'reminder_hours_before' => 24,
        ]);
    }

    /**
     * Sunbeds: paid up front, held for the whole day, sold as sets.
     */
    private function createSunbeds(Venue $venue): void
    {
        $bookingType = app(CreateBookingType::class)->handle($venue, [
            ...CreateBookingType::defaultsFor(BookingMode::ArrivalWindow),
            'name' => 'Sunbeds',
            'description' => 'Pick the row you like and tell us when you are coming. '
                .'Your set is yours for the day.',
            'resource_type' => ResourceType::SunbedSet,
            'booking_mode' => BookingMode::ArrivalWindow,
            'payment_requirement' => PaymentRequirement::Required,
            'deposit_label' => 'Deposit, deducted from your bill',
            'default_price_cents' => 2500,
            'price_basis' => PriceBasis::PerResource,
            'min_lead_time_minutes' => 60,
            'arrival_window_start' => '09:30',
            'arrival_window_end' => '14:00',
            'cancellation_policy' => [
                'text' => 'Free cancellation until the evening before. On the day, '
                    .'the deposit stays with us unless the weather closes the beach.',
            ],
        ]);

        $standard = ResourceTemplate::create([
            'name' => 'Beach set',
            'is_composite' => true,
            'components' => [
                ['label' => 'Sunbed', 'qty' => 2],
                ['label' => 'Umbrella', 'qty' => 1],
                ['label' => 'Side table', 'qty' => 1],
            ],
            'capacity_min' => 1,
            'capacity_max' => 2,
            'base_price_cents' => 2500,
            'price_basis' => PriceBasis::PerResource,
            'meta' => ['shade' => 'umbrella'],
        ]);

        $family = ResourceTemplate::create([
            'name' => 'Family set',
            'is_composite' => true,
            'components' => [
                ['label' => 'Sunbed', 'qty' => 4],
                ['label' => 'Umbrella', 'qty' => 2],
                ['label' => 'Side table', 'qty' => 1],
            ],
            'capacity_min' => 2,
            'capacity_max' => 5,
            'base_price_cents' => 4500,
            'price_basis' => PriceBasis::PerResource,
            'meta' => ['shade' => 'umbrella'],
        ]);

        // The front row sells for the whole day. The third row turns over at
        // lunchtime, which is the override the model exists for (SPEC §6.2.1).
        $front = $this->area($bookingType, 'Front row', 'Feet in the sand, nothing in front of you.', 1);
        $second = $this->area($bookingType, 'Second row', 'A few steps back, same view.', 2);
        $garden = $this->area($bookingType, 'Garden row', 'Under the tamarisk trees, shade all afternoon.', 3, [
            'occupancy' => Occupancy::FixedDuration,
            'max_stay_minutes' => 300,
            'turnover_buffer_minutes' => 45,
        ]);

        $bulk = app(BulkCreateResources::class);

        $bulk->handle($front, 'A', 1, 8, $standard, 3000);
        $bulk->handle($front, 'AF', 1, 2, $family, 5500);
        $bulk->handle($second, 'B', 1, 10, $standard, 2200);
        $bulk->handle($garden, 'G', 1, 6, $standard, 1600);

        // A believable Tuesday: a couple of sets held back for walk-ins, and
        // one taken out of service.
        $availability = app(SetResourceAvailability::class);

        foreach (['B9', 'B10'] as $label) {
            $resource = Resource::query()->where('label', $label)->first();

            if ($resource !== null) {
                $availability->one($resource, OnlineStatus::Closed);
            }
        }

        Resource::query()->where('label', 'G6')->update(['is_active' => false]);
    }

    /**
     * Tables: booked by the slot, paid at the table.
     */
    private function createTables(Venue $venue): void
    {
        $bookingType = app(CreateBookingType::class)->handle($venue, [
            ...CreateBookingType::defaultsFor(BookingMode::Slot),
            'name' => 'Tables',
            'description' => 'Dinner from seven. Tell us how many of you there are and '
                .'we will find the right table.',
            'resource_type' => ResourceType::Table,
            'booking_mode' => BookingMode::Slot,
            'payment_requirement' => PaymentRequirement::None,
            'default_price_cents' => 0,
            'price_basis' => PriceBasis::PerResource,
            'min_lead_time_minutes' => 90,
            'slot_duration_minutes' => 120,
            'cancellation_policy' => [
                'text' => 'Please call if your plans change. We hold a table for '
                    .'fifteen minutes past the booking time.',
            ],
            'max_stay_minutes' => 120,
        ]);

        $terrace = $this->area($bookingType, 'Terrace', 'Over the water, where the sun goes down.', 1);
        $inside = $this->area($bookingType, 'Dining room', 'Out of the wind, with the open kitchen.', 2);

        $bulk = app(BulkCreateResources::class);

        $bulk->handle($terrace, 'T', 1, 10);
        $bulk->handle($inside, 'D', 1, 8);

        // Two big tables on the terrace, kept for groups.
        Resource::query()
            ->whereIn('label', ['T9', 'T10'])
            ->update(['capacity_min' => 5, 'capacity_max' => 8]);
    }

    /**
     * How the demo takes money (SPEC §7.4).
     *
     * Cash on arrival is the one switched on, so the whole flow works from a
     * clean install with nobody's keys in it. Stripe sits beside it, filled
     * in and in test mode, so the screen shows what a configured gateway
     * looks like.
     */
    private function createPaymentSettings(): void
    {
        PaymentGatewaySetting::create([
            'gateway' => Gateway::CashOnArrival,
            'mode' => GatewayMode::Live,
            'is_active' => true,
        ]);

        $stripe = new PaymentGatewaySetting;
        $stripe->fill([
            'gateway' => Gateway::Stripe,
            'mode' => GatewayMode::Test,
            'statement_descriptor' => 'DEMO BEACH BAR',
            'is_active' => false,
        ]);
        $stripe->setCredentials([
            'secret_key' => 'sk_test_demo_not_a_real_key_0000000000',
            'webhook_secret' => 'whsec_demo_not_a_real_secret_000000',
        ]);
        $stripe->save();
    }

    /**
     * Three price rules, one of each shape, so the feature is visible without
     * anyone having to invent an example (SPEC §7.2).
     *
     * A venue with one price would have none of these — which is the point:
     * they are optional, and hidden until somebody wants them.
     */
    private function createPriceRules(): void
    {
        $sunbeds = BookingType::query()->where('name', 'Sunbeds')->firstOrFail();
        $front = Area::query()->where('name', 'Front row')->firstOrFail();
        $garden = Area::query()->where('name', 'Garden row')->firstOrFail();

        $year = CarbonImmutable::now(self::TIMEZONE)->year;

        // Friday to Sunday, everywhere on the beach.
        PriceRule::create([
            'scope' => StructureScope::BookingType,
            'scope_id' => $sunbeds->id,
            'name' => 'Weekend rate',
            'days_of_week' => [5, 6, 0],
            'price_type' => PriceRuleType::PercentModifier,
            'percent' => 20,
            'priority' => 10,
        ]);

        // High summer on the sand, whatever day it is — and it outranks the
        // weekend rule, which is what priority is for.
        PriceRule::create([
            'scope' => StructureScope::Area,
            'scope_id' => $front->id,
            'name' => 'High season',
            'date_from' => "{$year}-07-01",
            'date_to' => "{$year}-08-31",
            'price_type' => PriceRuleType::Fixed,
            'amount_cents' => 4000,
            'priority' => 20,
        ]);

        // Nobody wants the garden at noon; after three it is the best spot
        // in the place.
        PriceRule::create([
            'scope' => StructureScope::Area,
            'scope_id' => $garden->id,
            'name' => 'Late afternoon',
            'time_from' => '15:00',
            'time_to' => '19:00',
            'price_type' => PriceRuleType::PercentModifier,
            'percent' => -30,
            'priority' => 15,
        ]);
    }

    /**
     * One of each kind of closure, so all three levels are visible in the
     * dashboard without anyone having to create them (SPEC §6.2).
     *
     * No seasonal shutdown of the beach here on purpose: a demo opened in
     * January should still look like a working business.
     */
    private function createClosures(): void
    {
        $close = app(CloseBookings::class);

        $tables = BookingType::query()->where('name', 'Tables')->firstOrFail();
        $garden = Area::query()->where('name', 'Garden row')->firstOrFail();
        $broken = Resource::query()->where('label', 'B4')->firstOrFail();

        $saturday = CarbonImmutable::now(self::TIMEZONE)->next(CarbonImmutable::SATURDAY);

        $close->handle(
            subject: $tables,
            dateFrom: $saturday->toDateString(),
            reason: 'Private event — wedding party',
        );

        $close->handle(
            subject: $garden,
            dateFrom: CarbonImmutable::now(self::TIMEZONE)->addDays(3)->toDateString(),
            timeFrom: '09:00',
            timeTo: '12:00',
            reason: 'Tree work in the morning',
        );

        $close->handle(
            subject: $broken,
            dateFrom: CarbonImmutable::now(self::TIMEZONE)->toDateString(),
            dateTo: CarbonImmutable::now(self::TIMEZONE)->addDays(2)->toDateString(),
            reason: 'Broken umbrella, replacement ordered',
        );
    }

    /**
     * Three weeks behind and two ahead, in a shape that looks like a real
     * season: busy weekends, quiet Tuesdays, a few no-shows (SPEC §22.1).
     *
     * Everything is relative to today, so the demo reads correctly whenever
     * it is seeded.
     */
    private function createReservations(Venue $venue): void
    {
        $this->regulars = $this->buildGuestPool();

        $sunbeds = BookingType::query()->where('name', 'Sunbeds')->firstOrFail();
        $tables = BookingType::query()->where('name', 'Tables')->firstOrFail();

        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $taken = [];
        $created = 0;

        for ($offset = -21; $offset <= 14; $offset++) {
            $date = $today->addDays($offset);
            $busy = in_array($date->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true);

            $sunbedCount = $busy ? $this->faker->numberBetween(5, 9) : $this->faker->numberBetween(1, 4);
            $tableCount = $busy ? $this->faker->numberBetween(4, 7) : $this->faker->numberBetween(1, 3);

            $created += $this->reservationsForDay($venue, $sunbeds, $date, $sunbedCount, $offset, $taken);
            $created += $this->reservationsForDay($venue, $tables, $date, $tableCount, $offset, $taken);
        }

        $this->seedLiveHold($sunbeds, $today);

        $this->command?->line("  Seeded {$created} reservations for ".Guest::query()->count().' guests.');
    }

    /**
     * @param  array<string, true>  $taken
     */
    private function reservationsForDay(
        Venue $venue,
        BookingType $bookingType,
        CarbonImmutable $date,
        int $count,
        int $offset,
        array &$taken,
    ): int {
        $resources = Resource::query()
            ->where('booking_type_id', $bookingType->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($resources->isEmpty()) {
            return 0;
        }

        $isSunbeds = $bookingType->booking_mode === BookingMode::ArrivalWindow;
        $created = 0;

        foreach ($resources->shuffle()->take($count) as $resource) {
            $key = $date->toDateString().':'.$resource->id;

            if (isset($taken[$key])) {
                continue;
            }

            $taken[$key] = true;

            [$startsAt, $endsAt] = $isSunbeds
                ? $this->sunbedTimes($date, $resource)
                : $this->tableTimes($date);

            $this->storeReservation(
                venue: $venue,
                bookingType: $bookingType,
                resource: $resource,
                startsAt: $startsAt,
                endsAt: $endsAt,
                offset: $offset,
            );

            $created++;
        }

        return $created;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function sunbedTimes(CarbonImmutable $date, Resource $resource): array
    {
        $arrival = $date->setTime(9, 30)
            ->addMinutes(30 * $this->faker->numberBetween(0, 9));

        $area = $this->areaById($resource->area_id);

        // The garden row turns over at lunchtime; the rows on the sand are
        // yours until the beach closes.
        $endsAt = $area?->occupancy === Occupancy::FixedDuration
            ? $arrival->addMinutes((int) ($area->max_stay_minutes ?? 300))
            : $date->setTime(19, 0);

        return [$arrival->utc(), $endsAt->utc()];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function tableTimes(CarbonImmutable $date): array
    {
        $startsAt = $date->setTime(19, 0)
            ->addMinutes(30 * $this->faker->numberBetween(0, 7));

        return [$startsAt->utc(), $startsAt->addMinutes(120)->utc()];
    }

    private function storeReservation(
        Venue $venue,
        BookingType $bookingType,
        Resource $resource,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $offset,
    ): void {
        $partySize = $this->faker->numberBetween($resource->capacity_min, max($resource->capacity_min, $resource->capacity_max));
        $amount = $resource->priceCentsFor($partySize);

        [$status, $paymentStatus, $checkedInAt] = $this->statusFor($offset, $startsAt);

        $reservation = new Reservation;

        $person = $this->someone();

        $reservation->fill([
            'customer_name' => $person['name'],
            'customer_email' => $person['email'],
            'customer_phone' => $person['phone'],
            'locale' => 'en',
            'party_size' => $partySize,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'amount_cents' => $bookingType->needsPayment() ? $amount : 0,
            'deposit_label_snapshot' => $bookingType->deposit_label,
            'channel' => $this->faker->randomElement([
                Channel::Website, Channel::Widget, Channel::Widget,
                Channel::Phone, Channel::Walkin, Channel::Qr,
            ]),
            'notes' => $this->faker->boolean(20) ? $this->faker->randomElement([
                'Celebrating a birthday — a candle would be lovely.',
                'One of us is coeliac, is there gluten-free bread?',
                'We will arrive by boat, around half past.',
                'Please keep two sunbeds in the shade if possible.',
                'Could you have a bottle of white chilled for us?',
            ]) : null,
            'resource_snapshot' => [
                'label' => $resource->label,
                'components' => $resource->components,
                'area' => $this->areaById($resource->area_id)?->name,
            ],
        ]);

        $reservation->venue_id = $venue->id;
        $reservation->booking_type_id = $bookingType->id;
        $reservation->area_id = $resource->area_id;
        $reservation->resource_id = $resource->id;
        $reservation->checked_in_at = $checkedInAt;
        $reservation->created_at = $startsAt->subDays($this->faker->numberBetween(1, 10));

        // Matched into the venue's guest list exactly as a real booking would
        // be, so the demo's regulars really are the same person each time
        // (SPEC §28.2). The observer works the counters out from here.
        app(RecordGuest::class)->handle($reservation);

        if ($status === ReservationStatus::CancelledByVenue) {
            $reservation->cancelled_at = $startsAt->subDay();
            $reservation->cancellation_reason = 'Guest called to cancel';
        }

        // Every booking has had its voucher sent, and roughly one in twenty-five
        // bounced — which is about the real rate for addresses typed on a phone,
        // and the reason the dashboard has a warning for it at all (SPEC §10.3).
        if (! $status->isCancelled()) {
            $reservation->voucher_sent_at = $reservation->created_at;
            $reservation->voucher_delivery = $this->faker->boolean(4) ? 'bounced' : 'delivered';
        }

        if ($status === ReservationStatus::Confirmed && $startsAt->isFuture()) {
            // Reminders go out a day ahead, so anything further off has not
            // had one yet.
            $reservation->reminder_sent_at = now()->diffInHours($startsAt) < 24
                ? $startsAt->subDay()
                : null;
        }

        $reservation->save();
    }

    /**
     * Forty people, a few of whom will book again and again.
     *
     * @return list<array{name: string, email: string, phone: string}>
     */
    private function buildGuestPool(): array
    {
        $pool = [];

        foreach (range(1, 40) as $ignored) {
            $name = $this->faker->name();

            $pool[] = [
                'name' => $name,
                'email' => Str::slug($name).'@example.test',
                'phone' => $this->faker->randomElement(['+4477', '+3069', '+4915', '+3933'])
                    .$this->faker->numerify('########'),
            ];
        }

        return $pool;
    }

    /**
     * Weighted towards the first few, so the demo has regulars at the top of
     * the guest list and one-off visitors below them.
     *
     * @return array{name: string, email: string, phone: string}
     */
    private function someone(): array
    {
        $index = $this->faker->boolean(45)
            ? $this->faker->numberBetween(0, 7)
            : $this->faker->numberBetween(0, count($this->regulars) - 1);

        return $this->regulars[$index];
    }

    /**
     * @return array{0: ReservationStatus, 1: PaymentStatus, 2: ?CarbonImmutable}
     */
    private function statusFor(int $offset, CarbonImmutable $startsAt): array
    {
        if ($offset < 0) {
            return match ($this->faker->numberBetween(1, 10)) {
                1 => [ReservationStatus::NoShow, PaymentStatus::Paid, null],
                2 => [ReservationStatus::CancelledByVenue, PaymentStatus::Refunded, null],
                default => [ReservationStatus::Completed, PaymentStatus::Paid, $startsAt->addMinutes(5)],
            };
        }

        if ($offset === 0) {
            return $startsAt->isPast()
                ? [ReservationStatus::CheckedIn, PaymentStatus::Paid, $startsAt->addMinutes(3)]
                : [ReservationStatus::Confirmed, PaymentStatus::Paid, null];
        }

        return $this->faker->numberBetween(1, 12) === 1
            ? [ReservationStatus::PendingPayment, PaymentStatus::Pending, null]
            : [ReservationStatus::Confirmed, PaymentStatus::Paid, null];
    }

    /**
     * Somebody is at the payment screen right now — so the dashboard shows a
     * live hold rather than a feature nobody can see (SPEC §6.4).
     */
    private function seedLiveHold(BookingType $sunbeds, CarbonImmutable $today): void
    {
        $tomorrow = $today->addDay();

        // On a set nobody has booked, so the demo shows a spot being held
        // rather than one that is somehow both held and sold.
        $alreadyBooked = Reservation::query()
            ->whereBetween('starts_at', [$tomorrow->utc(), $tomorrow->endOfDay()->utc()])
            ->pluck('resource_id')
            ->filter()
            ->all();

        $resource = Resource::query()
            ->where('booking_type_id', $sunbeds->id)
            ->bookable()
            ->whereNotIn('id', $alreadyBooked)
            ->orderByDesc('sort_order')
            ->first();

        if ($resource === null) {
            return;
        }

        $startsAt = $tomorrow->setTime(11, 0)->utc();

        ReservationHold::create([
            'booking_type_id' => $sunbeds->id,
            'resource_id' => $resource->id,
            'starts_at' => $startsAt,
            'ends_at' => $tomorrow->setTime(19, 0)->utc(),
            'party_size' => 2,
            'session_token' => Str::random(40),
            'expires_at' => now()->addMinutes(9),
        ]);
    }

    /**
     * Private notes and tags on a couple of guests, so the card shows what the
     * field is for rather than an empty box (SPEC §28.3).
     */
    private function annotateGuests(): void
    {
        $notes = [
            'Always asks for the front row. Worth keeping one back on Saturdays.' => ['regular'],
            'Coeliac — the kitchen knows. Brings her mother in August.' => ['regular', 'allergy'],
            'Books for six and turns up with nine. Ask before confirming.' => ['careful'],
        ];

        $guests = Guest::query()->orderByDesc('bookings_count')->limit(3)->get();

        foreach ($guests as $index => $guest) {
            $note = array_keys($notes)[$index] ?? null;

            if ($note === null) {
                continue;
            }

            $guest->fill(['internal_notes' => $note, 'tags' => $notes[$note]])->save();
        }
    }

    /**
     * A few unread items in the bell, so that signing in to the demo shows what
     * the dashboard looks like in use rather than at rest (SPEC §22.1).
     *
     * @param  array<int, User>  $users
     */
    private function fillTheBell(Venue $venue, array $users): void
    {
        $recent = Reservation::query()
            ->where('venue_id', $venue->id)
            ->where('status', ReservationStatus::Confirmed)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(3)
            ->get();

        foreach ($recent as $reservation) {
            foreach ($users as $user) {
                $user->notify(new BookingArrived($reservation, (string) $venue->timezone));
            }
        }
    }

    private function areaById(?int $id): ?Area
    {
        if ($id === null) {
            return null;
        }

        return $this->areaCache[$id] ??= Area::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function area(
        BookingType $bookingType,
        string $name,
        string $description,
        int $sortOrder,
        array $overrides = [],
    ): Area {
        $area = new Area;

        $area->fill([
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
            ...$overrides,
        ]);
        $area->booking_type_id = $bookingType->id;
        $area->save();

        return $area;
    }

    /**
     * Copies a bundled image onto the media disk, so the demo has a real hero
     * and logo rather than an empty frame.
     */
    private function publishMedia(string $source, string $target): ?string
    {
        $path = database_path('seeders/media/'.$source);

        if (! is_file($path)) {
            return null;
        }

        $destination = self::MEDIA_DIR.'/'.$target;

        Storage::disk(config('media.disk'))->put($destination, file_get_contents($path));

        return $destination;
    }

    /**
     * The demo account is a paying customer (SPEC §4).
     *
     * On the monthly plan, two months in, with the history to match — so the
     * billing page shows what a real account's looks like rather than an
     * empty state. A demo that only ever shows the empty version of a screen
     * is a demo of the screen, not of the product.
     */
    private function createSubscription(): void
    {
        $plan = Plan::query()->where('slug', 'monthly')->first();

        if (! $plan instanceof Plan) {
            return;
        }

        $start = CarbonImmutable::now()->subMonths(2)->startOfDay();

        /*
         * The current period has to straddle today, or the demo account is
         * born expired — which is exactly what happened the first time this
         * was written: `$start->addMonths(2)` lands on today at midnight,
         * which is already in the past by the time anybody looks, so
         * SyncTenantStatus correctly marked the account lapsed and the whole
         * demo came up read-only.
         *
         * Written as two plain dates around now rather than as arithmetic on
         * $start, because the arithmetic is what got it wrong and the dates
         * are what the reader actually wants to know.
         */
        $subscription = Subscription::query()->create([
            'plan_id' => $plan->id,
            'status' => TenantStatus::Active,
            'current_period_start' => CarbonImmutable::now()->subDays(10)->startOfDay(),
            'current_period_end' => CarbonImmutable::now()->addDays(20)->startOfDay(),
            'provider' => BillingProvider::Stripe,

            /*
             * No `provider_subscription_id` on purpose. There is no
             * subscription at Stripe behind this row — the demo is seeded, not
             * bought — and putting a plausible-looking id here would make the
             * Cancel button call Stripe about something that does not exist.
             * Empty, the cancellation is simply recorded locally, which is
             * what a demo should do.
             */
            'price_cents' => $plan->price_cents,
            'currency' => 'EUR',
        ]);

        $events = app(RecordSubscriptionEvent::class);

        $subscription->setRelation('plan', $plan);

        $events->handle(
            $subscription,
            SubscriptionEventType::Started,
            'Started on Monthly, '.Money::format((int) $plan->price_cents).' per month.',
            (int) $plan->price_cents,
        );

        /*
         * One failed payment that recovered, because that is the sequence the
         * billing code actually produces and the one an owner will one day
         * ring about. A history that only ever says "renewed" demonstrates
         * the happy path and nothing else.
         *
         * History only — `payment_failed_at` stays null, so the demo account
         * is not sitting in past_due.
         */
        $events->handle(
            $subscription,
            SubscriptionEventType::PaymentFailed,
            'Payment failed: your card has expired.',
            (int) $plan->price_cents,
        );

        $events->handle(
            $subscription,
            SubscriptionEventType::Renewed,
            'Renewed until '.$subscription->current_period_end?->format('j M Y').', '
                .Money::format((int) $plan->price_cents).'.',
            (int) $plan->price_cents,
        );

        // Backdated so the history reads as two months rather than as three
        // things that happened in the same second.
        SubscriptionEvent::query()->where('subscription_id', $subscription->id)
            ->orderBy('id')->get()
            ->each(function (SubscriptionEvent $event, int $index) use ($start): void {
                $event->forceFill([
                    'occurred_at' => $start->addMonths($index),
                ])->save();
            });

        $this->tenantStatus();
    }

    private function tenantStatus(): void
    {
        $tenant = Tenant::query()->where('slug', self::SLUG)->first();

        if ($tenant instanceof Tenant) {
            app(SyncTenantStatus::class)->handle($tenant);
        }
    }

    /**
     * The demo account's two API tokens (SPEC §15.2).
     *
     * Fixed values, so the curl lines in the documentation and in any demo
     * can be pasted as they are. Safe precisely because they are published:
     * nobody can mistake them for a real venue's, and the demo account holds
     * nothing but invented bookings.
     */
    private function createApiTokens(): void
    {
        foreach ([ApiTokenMode::Live, ApiTokenMode::Test] as $mode) {
            $token = $mode->tokenPrefix().self::API_TOKEN_BODY;

            ApiToken::query()->create([
                'mode' => $mode,
                'token' => $token,
                'token_hash' => ApiToken::hash($token),
                'prefix' => $mode->tokenPrefix().substr(self::API_TOKEN_BODY, 0, 4),
            ]);
        }
    }

    private function report(): void
    {
        $this->command?->newLine();
        $this->command?->info(self::NAME.' is ready.');
        $this->command?->line('  Public page:  /reservation/'.self::SLUG);
        $this->command?->line('  Owner:        '.self::OWNER_EMAIL.' / '.self::PASSWORD);
        $this->command?->line('  Staff:        '.self::STAFF_EMAIL.' / '.self::PASSWORD);
        $this->command?->line('  API token:    '.ApiTokenMode::Live->tokenPrefix().self::API_TOKEN_BODY);

        if (config('media.disk') === 'public' && ! file_exists(public_path('storage'))) {
            $this->command?->newLine();
            $this->command?->warn('  Run `php artisan storage:link` to see the photos.');
        }

        if (config('mail.default') === 'log') {
            $this->command?->newLine();
            $this->command?->line('  Email is going to the log. `php artisan pail` to watch it,');
            $this->command?->line('  or MAIL_MAILER=smtp with Brevo credentials to send for real.');
        }
    }

    /**
     * Re-running rebuilds the demo and touches nothing else.
     */
    private function wipeExistingDemo(): void
    {
        $existing = Tenant::withTrashed()->where('slug', self::SLUG)->first();

        if ($existing === null) {
            return;
        }

        $users = DB::table('users')
            ->whereIn('email', [self::OWNER_EMAIL, self::STAFF_EMAIL])
            ->pluck('id');

        // Notifications hang off the user by a polymorphic key, so no foreign
        // key takes them with it.
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $users)
            ->delete();

        DB::table('users')->whereIn('id', $users)->delete();

        Storage::disk(config('media.disk'))->deleteDirectory(self::MEDIA_DIR);

        $existing->forceDelete();
    }
}
