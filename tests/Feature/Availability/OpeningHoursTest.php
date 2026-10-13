<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Availability\OpeningHoursResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Opening hours into instants (SPEC §6.1, §3.5).
 */
final class OpeningHoursTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private OpeningHoursResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner();
        $this->resolver = new OpeningHoursResolver;
    }

    #[Test]
    public function a_day_turns_into_one_window_in_utc(): void
    {
        $bookingType = $this->asTenant(fn () => $this->bookingTypeOpen('09:00', '19:00'));

        $day = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-07-04', 'Europe/Athens'));

        $this->assertFalse($day->isClosed());
        $this->assertCount(1, $day->windows);

        // Athens is UTC+3 in July.
        $this->assertSame('2026-07-04 06:00', $day->opensAt()?->format('Y-m-d H:i'));
        $this->assertSame('2026-07-04 16:00', $day->closesAt()?->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_bar_that_closes_at_two_is_still_working_the_night_before(): void
    {
        $bookingType = $this->asTenant(
            fn () => $this->bookingTypeOpen('19:00', '02:00', [], closesNextDay: true)
        );

        $day = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-07-04', 'Europe/Athens'));

        $this->assertSame('2026-07-04 16:00', $day->opensAt()?->format('Y-m-d H:i'));
        $this->assertSame('2026-07-04 23:00', $day->closesAt()?->format('Y-m-d H:i'));

        // 01:30 local on the 5th belongs to the 4th's shift.
        $this->assertTrue($day->contains(CarbonImmutable::parse('2026-07-05 01:30', 'Europe/Athens')->utc()));
    }

    /**
     * Nobody ticks a box to say "we close after midnight" — they just type
     * 02:00 and expect it to work.
     */
    #[Test]
    public function a_closing_time_before_the_opening_time_is_understood_without_the_flag(): void
    {
        $bookingType = $this->asTenant(
            fn () => $this->bookingTypeOpen('19:00', '02:00', [], closesNextDay: false)
        );

        $day = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-07-04', 'Europe/Athens'));

        $this->assertSame(420, (int) $day->opensAt()?->diffInMinutes($day->closesAt()));
    }

    #[Test]
    public function lunch_and_dinner_are_two_windows_in_one_day(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->bookingTypeOpen('12:00', '16:00');
            $this->addHours($type, '19:00', '23:00');

            return $type;
        });

        $day = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-07-04', 'Europe/Athens'));

        $this->assertCount(2, $day->windows);
        $this->assertSame('09:00', $day->opensAt()?->format('H:i'));
        $this->assertSame('20:00', $day->closesAt()?->format('H:i'));

        // The gap in the afternoon is genuinely shut.
        $this->assertFalse($day->contains(CarbonImmutable::parse('2026-07-04 17:00', 'Europe/Athens')->utc()));
    }

    #[Test]
    public function a_day_with_no_hours_is_closed(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->bookingTypeOpen('19:00', '23:00');
            $this->closeOn($type, CarbonImmutable::MONDAY);

            return $type;
        });

        $monday = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-07-06', 'Europe/Athens'));
        $tuesday = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-07-07', 'Europe/Athens'));

        $this->assertTrue($monday->isClosed());
        $this->assertFalse($tuesday->isClosed());
    }

    /**
     * The night the clocks go forward, 03:00 to 04:00 local never happens in
     * Athens. A venue that opens then opens an hour later — it does not open
     * at a time that did not exist (SPEC §3.5).
     */
    #[Test]
    public function the_night_the_clocks_change_forward_still_produces_a_real_day(): void
    {
        $bookingType = $this->asTenant(
            fn () => $this->bookingTypeOpen('22:00', '05:00', [], closesNextDay: true)
        );

        $day = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-03-28', 'Europe/Athens'));

        $opensAt = $day->opensAt();
        $closesAt = $day->closesAt();

        $this->assertNotNull($opensAt);
        $this->assertNotNull($closesAt);

        // 22:00 EET (+2) to 05:00 EEST (+3): six hours of real time, not seven.
        $this->assertSame(360, (int) $opensAt->diffInMinutes($closesAt));
    }

    /**
     * And the night they go back, the same wall clock runs twice, so the
     * venue really is open an hour longer.
     */
    #[Test]
    public function the_night_the_clocks_change_back_is_an_hour_longer(): void
    {
        $bookingType = $this->asTenant(
            fn () => $this->bookingTypeOpen('22:00', '05:00', [], closesNextDay: true)
        );

        $day = $this->asTenant(fn () => $this->resolver->resolve($bookingType, '2026-10-24', 'Europe/Athens'));

        $this->assertSame(
            480,
            (int) $day->opensAt()?->diffInMinutes($day->closesAt())
        );
    }
}
