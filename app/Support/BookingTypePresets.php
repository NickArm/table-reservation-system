<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BookingMode;
use App\Enums\PaymentRequirement;
use App\Enums\ResourceType;

/**
 * The two answers to "what do you take bookings for?" (SPEC §1.1).
 *
 * A taverna owner should not have to understand occupancy models to get
 * started. One tap produces a booking type that behaves the way the word on
 * the button suggests; everything is editable afterwards.
 *
 * The values are form input, deliberately — the preset buttons post the same
 * request the full form does, so there is one validated path in.
 */
final class BookingTypePresets
{
    /**
     * @return array<string, array{label: string, fields: array<string, string|int>}>
     */
    public static function all(): array
    {
        return [
            'tables' => [
                'label' => 'Tables',
                'fields' => [
                    'name' => 'Tables',
                    'resource_type' => ResourceType::Table->value,
                    'booking_mode' => BookingMode::Slot->value,

                    // Most tavernas take the booking and the money at the
                    // table. Those who want a deposit switch this over.
                    'payment_requirement' => PaymentRequirement::None->value,
                    'default_price' => 0,
                    'min_lead_time_minutes' => 60,
                ],
            ],

            'sunbeds' => [
                'label' => 'Sunbeds',
                'fields' => [
                    'name' => 'Sunbeds',
                    'resource_type' => ResourceType::SunbedSet->value,
                    'booking_mode' => BookingMode::ArrivalWindow->value,

                    // A sunbed held all day for someone who never turns up is
                    // a lost day, so this one is paid for up front.
                    'payment_requirement' => PaymentRequirement::Required->value,
                    'default_price' => 20,
                    'deposit_label' => 'Deposit, deducted from your bill',
                    'min_lead_time_minutes' => 60,
                ],
            ],
        ];
    }
}
