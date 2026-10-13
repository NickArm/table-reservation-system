<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;

/**
 * Which level of the structure a rule attaches to.
 *
 * Shared by closures (SPEC §6.2) and price rules (§7.2), because both answer
 * exactly the same question: everything, one area, or one spot. Two enums
 * with identical cases would drift apart the first time a fourth level
 * appears.
 *
 * The beach shuts from November to April while the dining room carries on,
 * and the front row costs more than the back: neither belongs to the venue as
 * a whole, which is why nothing here is venue-wide.
 */
enum StructureScope: string
{
    case BookingType = 'booking_type';
    case Area = 'area';
    case Resource = 'resource';

    /**
     * @return class-string<BookingType|Area|Resource>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::BookingType => BookingType::class,
            self::Area => Area::class,
            self::Resource => Resource::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BookingType => 'Everything',
            self::Area => 'One area',
            self::Resource => 'One spot',
        };
    }
}
