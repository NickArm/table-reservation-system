<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of place this is (SPEC §33.1).
 *
 * Was a free string matched in one `match` inside the booking page
 * controller, which is the thing the conventions in CLAUDE.md exist to stop:
 * a closed set written as bare strings in one place and typed by hand in the
 * seeder in another.
 *
 * The list is short and Greek-shaped on purpose. It is not a taxonomy — it
 * decides one thing, which is the `@type` Google is given for the page
 * (§33.2), and a longer list of nearly identical options is a dropdown an
 * owner reads twice and still picks wrong.
 */
enum VenueCategory: string
{
    case Restaurant = 'restaurant';
    case Taverna = 'taverna';
    case BeachBar = 'beach_bar';
    case Cafe = 'cafe';
    case Bar = 'bar';
    case Club = 'club';
    case Hotel = 'hotel';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => 'Restaurant',
            self::Taverna => 'Taverna',
            self::BeachBar => 'Beach bar',
            self::Cafe => 'Café',
            self::Bar => 'Bar',
            self::Club => 'Club',
            self::Hotel => 'Hotel',
        };
    }

    /**
     * The schema.org type for this kind of place (SPEC §33.2).
     *
     * The whole reason the field exists. Google reads this to decide whether
     * the page is eligible for the richer treatment that restaurants get, and
     * a wrong type is worse than a missing one.
     */
    public function schemaType(): string
    {
        return match ($this) {
            self::Restaurant, self::Taverna => 'Restaurant',
            self::BeachBar, self::Cafe, self::Bar, self::Club => 'BarOrPub',
            self::Hotel => 'Hotel',
        };
    }

    /**
     * Does what they serve matter to a search engine here?
     *
     * `servesCuisine` is only meaningful on a Restaurant, so the field is
     * shown on the form for the two that are one and hidden for the rest —
     * rather than asked of a beach bar and then silently dropped.
     */
    public function servesCuisine(): bool
    {
        return $this->schemaType() === 'Restaurant';
    }
}
