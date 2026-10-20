<?php

declare(strict_types=1);

namespace App\Actions\Structure;

use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Enums\PlanLimit;
use App\Models\BookingType;
use App\Models\OpeningHour;
use App\Models\Venue;
use App\Subscriptions\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a booking type with hours it can actually take bookings in.
 *
 * A booking type with no hours silently accepts nothing, which looks like a
 * bug to whoever set it up — so sensible hours come with it and can be edited
 * afterwards.
 */
final class CreateBookingType
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Venue $venue, array $attributes): BookingType
    {
        // Checked here rather than in the controller, because the API and the
        // console reach this same action (SPEC §4.5).
        $this->entitlements->ensureRoomFor(PlanLimit::BookingTypes);

        return DB::transaction(function () use ($venue, $attributes): BookingType {
            $bookingType = new BookingType;

            $bookingType->fill($attributes);
            $bookingType->venue_id = $venue->id;
            $bookingType->slug = $this->uniqueSlug($venue, (string) $attributes['name']);
            $bookingType->sort_order = (int) BookingType::query()->max('sort_order') + 1;
            $bookingType->save();

            $this->seedOpeningHours($bookingType);

            return $bookingType;
        });
    }

    private function uniqueSlug(Venue $venue, string $name): string
    {
        $base = Str::slug($name) ?: 'bookings';
        $slug = $base;
        $suffix = 1;

        while (BookingType::query()
            ->where('venue_id', $venue->id)
            ->where('slug', $slug)
            ->exists()
        ) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Opening every day, with hours that suit the kind of booking.
     */
    private function seedOpeningHours(BookingType $bookingType): void
    {
        [$opens, $closes] = $bookingType->booking_mode === BookingMode::Slot
            ? ['18:00', '23:30']
            : ['09:00', '19:00'];

        foreach (range(0, 6) as $day) {
            OpeningHour::create([
                'scope' => OpeningHour::SCOPE_BOOKING_TYPE,
                'scope_id' => $bookingType->id,
                'day_of_week' => $day,
                'opens_at' => $opens,
                'closes_at' => $closes,
            ]);
        }
    }

    /**
     * Defaults that make each mode behave the way its name suggests.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(BookingMode $mode): array
    {
        return match ($mode) {
            BookingMode::Slot => [
                'slot_duration_minutes' => 90,
                'slot_interval_minutes' => 30,
                'turnover_buffer_minutes' => 15,
                'occupancy' => Occupancy::FixedDuration,
                'max_stay_minutes' => 90,
                'arrival_window_start' => null,
                'arrival_window_end' => null,
            ],
            BookingMode::ArrivalWindow => [
                'slot_interval_minutes' => 30,
                'turnover_buffer_minutes' => 30,
                'occupancy' => Occupancy::WholeDay,
                'max_stay_minutes' => null,
                'arrival_window_start' => '10:00',
                'arrival_window_end' => '13:00',
            ],
            BookingMode::HalfDay, BookingMode::FullDay => [
                'turnover_buffer_minutes' => 30,
                'occupancy' => Occupancy::WholeDay,
                'max_stay_minutes' => null,
                'arrival_window_start' => null,
                'arrival_window_end' => null,
            ],
        };
    }
}
