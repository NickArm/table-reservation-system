<?php

declare(strict_types=1);

namespace App\Actions\Venues;

use App\Enums\VenueImage;
use App\Media\ImageStore;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * How the booking page looks (SPEC §5.5, §27).
 *
 * One accent colour and two images, and that is the whole of it. The design
 * decision behind the screen this serves is that a venue gets to be
 * recognisable, not to redesign anything: a neutral shell with one colour
 * from the tenant looks deliberate on four hundred venues, and a free choice
 * of fonts and backgrounds looks like four hundred different mistakes.
 */
final readonly class SaveVenueBranding
{
    public function __construct(private ImageStore $images) {}

    /**
     * @param  array<string, UploadedFile|null>  $uploads  keyed by VenueImage value
     * @param  array<int, string>  $remove  VenueImage values to clear
     */
    public function handle(
        Venue $venue,
        string $tenantUuid,
        ?string $accentColor,
        int $heroOverlay,
        array $uploads = [],
        array $remove = [],
    ): Venue {
        return DB::transaction(function () use (
            $venue,
            $tenantUuid,
            $accentColor,
            $heroOverlay,
            $uploads,
            $remove,
        ): Venue {
            $changes = ['hero_overlay' => $heroOverlay];

            /*
             * Only when one was sent. `venues.accent_color` is NOT NULL with
             * a default, so "nothing submitted" has to mean "leave it alone"
             * — writing null would fail the constraint and take the whole
             * save down with it, including the image that had just been
             * stored. The same rule as an empty file input.
             */
            if ($accentColor !== null && $accentColor !== '') {
                $changes['accent_color'] = $accentColor;
            }

            foreach (VenueImage::cases() as $kind) {
                $current = $venue->{$kind->column()};
                $upload = $uploads[$kind->value] ?? null;

                /*
                 * An upload wins over a removal. Ticking "remove" and
                 * choosing a new file in the same submission is somebody
                 * replacing an image, and the obvious reading of that is the
                 * new one — not nothing.
                 */
                if ($upload instanceof UploadedFile) {
                    $changes[$kind->column()] = $this->images->put(
                        $kind,
                        $upload,
                        $tenantUuid,
                        is_string($current) ? $current : null,
                    );

                    continue;
                }

                if (in_array($kind->value, $remove, true)) {
                    $this->images->forget(is_string($current) ? $current : null);

                    $changes[$kind->column()] = null;
                }

                // Neither: the field is left alone. An empty file input is
                // "I did not change this", never "delete it".
            }

            $venue->forceFill($changes)->save();

            return $venue;
        });
    }
}
