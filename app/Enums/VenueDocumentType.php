<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A venue's own legal pages (SPEC §12.1).
 *
 * Two, not three. A venue's privacy notice is ours to make — the guest's data
 * is processed by this platform under the DPA (§12.2) — so asking a taverna
 * owner to write one would produce either a blank page or something copied
 * off a competitor's site.
 */
enum VenueDocumentType: string
{
    case Terms = 'terms';
    case CancellationPolicy = 'cancellation_policy';

    /**
     * The last segment of its public address, under the venue's own page.
     */
    public function path(): string
    {
        return match ($this) {
            self::Terms => 'terms',
            self::CancellationPolicy => 'cancellation-policy',
        };
    }

    /**
     * The type behind one of those paths.
     *
     * On the enum rather than in a controller because two controllers need
     * it — the venue's own screen and the public page — and a second copy of
     * the hyphen-to-underscore translation is a 404 waiting to happen in
     * whichever of the two was written second. It was.
     */
    public static function fromPath(string $path): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->path() === $path) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Every public path, for constraining a route.
     *
     * @return array<int, string>
     */
    public static function paths(): array
    {
        return array_map(static fn (self $case): string => $case->path(), self::cases());
    }

    public function title(): string
    {
        return match ($this) {
            self::Terms => 'Terms of Booking',
            self::CancellationPolicy => 'Cancellation Policy',
        };
    }

    /**
     * What the owner is told the page is for, above the box they type in.
     */
    public function explain(): string
    {
        return match ($this) {
            self::Terms => 'The conditions a guest agrees to when they book with you '
                .'— what the payment is, what happens if they are late, house rules.',
            self::CancellationPolicy => 'Whether and when a guest gets their money '
                .'back. Guests agree to this at checkout and it is printed on their '
                .'booking confirmation.',
        };
    }
}
