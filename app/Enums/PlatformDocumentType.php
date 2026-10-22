<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The platform's own legal documents (SPEC §12.2).
 *
 * Four, and the list is closed: these are the four a SaaS has to publish in
 * the EU. A fifth would need a route, a page and a reason.
 */
enum PlatformDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Dpa = 'dpa';
    case Cookies = 'cookies';

    /**
     * The address it is published at.
     *
     * Part of the enum rather than the routes file, because these URLs end up
     * in contracts, in emails and on other people's websites. They are a
     * promise, not a detail.
     */
    public function path(): string
    {
        return match ($this) {
            self::Terms => 'terms',
            self::Privacy => 'privacy',
            self::Dpa => 'dpa',
            self::Cookies => 'cookies',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Terms => 'Terms of Service',
            self::Privacy => 'Privacy Policy',
            self::Dpa => 'Data Processing Agreement',
            self::Cookies => 'Cookie Notice',
        };
    }

    /**
     * One line saying who it is for, shown on the admin list.
     */
    public function who(): string
    {
        return match ($this) {
            self::Terms => 'What venues agree to when they sign up.',
            self::Privacy => 'What we do with personal data, for anybody who asks.',
            self::Dpa => 'Venue is the controller of its guest list, we are the processor.',
            self::Cookies => 'Short, because there is nothing but the session cookie.',
        };
    }

    /**
     * Does a venue have to agree to this one to use the product?
     *
     * Terms and the DPA are the contract; the privacy and cookie notices are
     * information. Only the first two can force a re-acceptance banner.
     */
    public function isAgreedTo(): bool
    {
        return $this === self::Terms || $this === self::Dpa;
    }
}
