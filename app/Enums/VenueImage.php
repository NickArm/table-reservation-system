<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two images a venue uploads (SPEC §5.5).
 *
 * Two, and deliberately no more. A gallery is a Phase 2 feature and a
 * decision; these are the ones the booking page cannot do without — the logo
 * so a guest recognises where they are, and the hero so the page looks like
 * the place rather than like a form.
 */
enum VenueImage: string
{
    case Logo = 'logo';
    case Hero = 'hero';

    public function column(): string
    {
        return $this->value.'_path';
    }

    public function label(): string
    {
        return match ($this) {
            self::Logo => 'Logo',
            self::Hero => 'Hero image',
        };
    }

    public function maxWidth(): int
    {
        return (int) config('media.'.$this->value.'.max_width');
    }

    /**
     * Does a transparent background matter?
     *
     * A logo nearly always has one, and flattening it onto white puts a white
     * rectangle on a dark page header — so logos are written as PNG whatever
     * arrived. A hero is a photograph with nothing to keep transparent, and
     * JPEG at the quality in config is a third of the size, which is the
     * whole point on a beach with one bar of signal.
     */
    public function keepsTransparency(): bool
    {
        return $this === self::Logo;
    }

    public function extension(): string
    {
        return $this->keepsTransparency() ? 'png' : 'jpg';
    }

    /**
     * Where it lives, under the account's own folder (SPEC §30.1).
     *
     * By tenant uuid rather than id, so a path reveals nothing about how many
     * venues there are, and so deleting an account is one folder (§29.3).
     */
    public function pathFor(string $tenantUuid, string $name): string
    {
        return 'tenants/'.$tenantUuid.'/venue/'.$this->value.'/'.$name.'.'.$this->extension();
    }

    public function hint(): string
    {
        return match ($this) {
            self::Logo => 'A square-ish PNG with a transparent background works best. '
                .'It appears on your booking page, on confirmation emails and on the '
                .'booking voucher.',
            self::Hero => 'A wide photograph of the place — the terrace, the beach, the '
                .'room. It is the first thing a guest sees, so a picture taken in '
                .'daylight beats a dark one taken during service.',
        };
    }
}
