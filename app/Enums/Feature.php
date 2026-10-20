<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a plan switches on (SPEC §4.1).
 *
 * An enum rather than loose strings, because a feature check misspelled in a
 * controller fails open: `$plan->features['embed_wigdet'] ?? false` is false
 * for every plan, and the venue paying for the widget quietly does not have
 * it. A typo here is a fatal error instead.
 */
enum Feature: string
{
    case EmbedWidget = 'embed_widget';
    case FloorPlanEditor = 'floor_plan_editor';
    case CompositeSets = 'composite_sets';
    case CustomDomain = 'custom_domain';
    case SmsNotifications = 'sms_notifications';
    case MultiVenue = 'multi_venue';
    case ApiAccess = 'api_access';
    case RemoveBranding = 'remove_branding';

    public function label(): string
    {
        return match ($this) {
            self::EmbedWidget => 'Website widget',
            self::FloorPlanEditor => 'Floor plan editor',
            self::CompositeSets => 'Sunbed sets',
            self::CustomDomain => 'Your own domain',
            self::SmsNotifications => 'Text messages',
            self::MultiVenue => 'More than one venue',
            self::ApiAccess => 'API access',
            self::RemoveBranding => 'No our-name footer',
        };
    }

    /**
     * What the owner is told when their plan does not include it.
     */
    public function refusal(): string
    {
        return match ($this) {
            self::EmbedWidget => 'The website widget is not part of your plan.',
            self::FloorPlanEditor => 'The floor plan editor is not part of your plan. '
                .'You can still list your tables row by row.',
            self::CompositeSets => 'Sunbed sets are not part of your plan. '
                .'Single spots work as normal.',
            self::CustomDomain => 'Using your own domain is not part of your plan.',
            self::SmsNotifications => 'Text messages are not part of your plan. '
                .'Email notifications work as normal.',
            self::MultiVenue => 'Your plan covers one venue.',
            self::ApiAccess => 'API access is not part of your plan.',
            self::RemoveBranding => 'Removing the footer is not part of your plan.',
        };
    }
}
