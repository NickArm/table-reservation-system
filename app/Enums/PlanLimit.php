<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a plan caps (SPEC §4.1, §4.5).
 *
 * At a limit, creating the next one is refused and nothing already there is
 * touched — not on a downgrade either. A system that deletes a venue's tables
 * because their card changed is a system nobody trusts with a season.
 */
enum PlanLimit: string
{
    case Venues = 'max_venues';
    case BookingTypes = 'max_booking_types';
    case Resources = 'max_resources';
    case ReservationsPerMonth = 'max_reservations_month';
    case StaffUsers = 'max_staff_users';

    public function label(): string
    {
        return match ($this) {
            self::Venues => 'venues',
            self::BookingTypes => 'booking types',
            self::Resources => 'tables and sunbeds',
            self::ReservationsPerMonth => 'bookings a month',
            self::StaffUsers => 'staff logins',
        };
    }

    /**
     * What the owner is told at the limit.
     *
     * Each one names the number and what to do about it, because "limit
     * reached" with no number is the kind of message that generates a support
     * email rather than an upgrade.
     */
    public function refusal(int $limit): string
    {
        return match ($this) {
            self::Venues => "Your plan covers {$limit} ".($limit === 1 ? 'venue' : 'venues').'.',
            self::BookingTypes => "Your plan covers {$limit} booking "
                .($limit === 1 ? 'type' : 'types').'.',
            self::Resources => "Your plan covers {$limit} tables or sunbeds. "
                .'Nothing has been removed — you can close one to add another, or move up a plan.',
            self::ReservationsPerMonth => "Your plan covers {$limit} bookings a month, and this "
                .'month is full. Bookings already made are unaffected, and the count resets on the 1st.',
            self::StaffUsers => "Your plan covers {$limit} staff "
                .($limit === 1 ? 'login' : 'logins').'.',
        };
    }
}
