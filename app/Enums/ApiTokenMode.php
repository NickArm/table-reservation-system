<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which of an account's two tokens this is (SPEC §15.2).
 *
 * Separate from GatewayMode, which looks identical and means something else:
 * that one decides whether a guest's card is really charged and puts a banner
 * across the public page. This one decides nothing about money — it exists so
 * a developer wiring up a custom form can work against a token that is
 * obviously not the production one, and so a leaked token can be identified
 * at a glance from its prefix.
 */
enum ApiTokenMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function isLive(): bool
    {
        return $this === self::Live;
    }

    /**
     * The visible start of every token in this mode.
     *
     * Prefixed on purpose: a secret scanner, a log reviewer and the owner
     * themselves can all tell what they are looking at without a lookup.
     */
    public function tokenPrefix(): string
    {
        return 'tr_'.$this->value.'_';
    }

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Test',
            self::Live => 'Live',
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Test => 'For your developer to build against. Returns the same '
                .'shapes as live, so a form written here works unchanged.',
            self::Live => 'Your real availability, read by your own server.',
        };
    }
}
