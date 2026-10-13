<?php

declare(strict_types=1);

namespace App\Availability;

use Carbon\CarbonImmutable;

/**
 * Turns a venue's wall clock into an instant (SPEC §3.5).
 *
 * The night the clocks change, an hour either does not exist or exists twice.
 * `strict()` refuses the first case; `normalized()` accepts whatever PHP makes
 * of it. The difference matters: a venue whose opening time falls in the
 * skipped hour simply opens an hour later, but a *slot* at a time that never
 * happens must never be offered to a guest.
 *
 * Ambiguous times — the hour that runs twice in autumn — resolve to the first
 * occurrence, which is PHP's own behaviour and the one a guest assumes.
 */
final class LocalTime
{
    /**
     * Null when that wall time does not exist on that date.
     */
    public static function strict(string $date, string $time, string $timezone): ?CarbonImmutable
    {
        $time = self::normalizeTimeString($time);

        $local = self::build($date, $time, $timezone);

        if ($local === null) {
            return null;
        }

        // PHP shifts a non-existent local time forward. If what came back is
        // not the time we asked for, that time did not happen.
        return $local->format('H:i') === $time ? $local->utc() : null;
    }

    /**
     * Always an instant: the time asked for, or the first one after it that
     * actually exists.
     */
    public static function normalized(string $date, string $time, string $timezone): CarbonImmutable
    {
        $local = self::build($date, self::normalizeTimeString($time), $timezone);

        return ($local ?? CarbonImmutable::parse($date, $timezone)->startOfDay())->utc();
    }

    private static function build(string $date, string $time, string $timezone): ?CarbonImmutable
    {
        // The leading "!" resets every unspecified field, so seconds do not
        // leak in from the current time.
        $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, $timezone);

        return $local === false ? null : $local;
    }

    /**
     * Times arrive from the database as "18:00:00" and from forms as "18:00".
     */
    private static function normalizeTimeString(string $time): string
    {
        return substr($time, 0, 5);
    }
}
