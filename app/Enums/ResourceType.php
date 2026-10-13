<?php

declare(strict_types=1);

namespace App\Enums;

enum ResourceType: string
{
    case Table = 'table';
    case Sunbed = 'sunbed';
    case SunbedSet = 'sunbed_set';
    case Cabana = 'cabana';
    case UmbrellaSet = 'umbrella_set';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Table => 'Table',
            self::Sunbed => 'Sunbed',
            self::SunbedSet => 'Sunbed set',
            self::Cabana => 'Cabana',
            self::UmbrellaSet => 'Umbrella set',
            self::Custom => 'Other',
        };
    }

    /** Plural, for headings and counts. */
    public function plural(): string
    {
        return match ($this) {
            self::Table => 'tables',
            self::Sunbed => 'sunbeds',
            self::SunbedSet => 'sunbed sets',
            self::Cabana => 'cabanas',
            self::UmbrellaSet => 'umbrella sets',
            self::Custom => 'spots',
        };
    }

    /**
     * Area names to offer on an empty setup screen, so the first one is a tap
     * rather than a blank field (SPEC §1.1).
     *
     * @return array<int, string>
     */
    public function suggestedAreas(): array
    {
        return match ($this) {
            self::Table => ['Dining room', 'Terrace', 'Garden'],
            self::Sunbed, self::SunbedSet, self::UmbrellaSet => ['1st row', '2nd row', '3rd row'],
            self::Cabana => ['Beachfront', 'Poolside'],
            self::Custom => ['Main area'],
        };
    }

    /** Sold as one unit made of several things, by default. */
    public function isUsuallyComposite(): bool
    {
        return match ($this) {
            self::SunbedSet, self::UmbrellaSet, self::Cabana => true,
            self::Table, self::Sunbed, self::Custom => false,
        };
    }
}
