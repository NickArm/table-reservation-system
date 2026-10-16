<?php

declare(strict_types=1);

namespace App\Api\V1;

/**
 * The machine-readable version of what Payload promises (SPEC §15.2).
 *
 * Served so a customer's developer can generate a client instead of typing
 * one, and so the contract has a second, checkable statement of itself: if
 * this and Payload disagree, one of them is a bug.
 *
 * Hand-written rather than generated from annotations. The surface is six
 * endpoints and will stay small; a generator would add a dependency, a build
 * step and a layer of indirection to save less than this file costs.
 */
final readonly class OpenApi
{
    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('app.name').' API',
                'version' => '1.0.0',
                'description' => 'Read a venue\'s availability. Bookings and payments '
                    .'happen on the venue\'s own booking page, where the availability '
                    .'engine and the hold live — so a custom form asks these endpoints '
                    .'what is free, then sends the visitor there.',
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Your account token, from Settings → Developer. '
                            .'Server-side only: a call that arrives with an Origin header '
                            .'is flagged in your dashboard as exposed.',
                    ],
                ],
                'parameters' => [
                    'type' => self::param('type', 'query', 'Booking type slug. Omit when the venue has only one.', 'string'),
                    'people' => self::param('people', 'query', 'Party size. Defaults to 1.', 'integer'),
                    'date' => self::param('date', 'query', 'Local date as YYYY-MM-DD.', 'string'),
                    'month' => self::param('month', 'query', 'Local month as YYYY-MM. Defaults to the current one.', 'string'),
                    'time' => self::param('time', 'query', 'Local time as HH:MM, 24-hour.', 'string'),
                ],
                'schemas' => self::schemas(),
                'responses' => [
                    'Error' => [
                        'description' => 'Every failure, in one shape.',
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'properties' => ['error' => [
                                'type' => 'object',
                                'properties' => [
                                    'code' => ['type' => 'string', 'examples' => ['invalid_token', 'not_found', 'rate_limited']],
                                    'message' => ['type' => 'string'],
                                ],
                            ]],
                        ]]],
                    ],
                ],
            ],
            'paths' => self::paths(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paths(): array
    {
        return [
            '/venue' => self::get('venue', 'The venue, its timezone and its currency.', 'Venue'),
            '/booking-types' => self::get(
                'bookingTypes',
                'What the venue takes bookings for, with the bounds a date picker needs.',
                'BookingType',
                list: true,
            ),
            '/days' => self::get(
                'days',
                'A month at a glance, for colouring a calendar. One call per month.',
                'Day',
                list: true,
                params: ['type', 'month', 'people'],
            ),
            '/times' => self::get(
                'times',
                'The times offered on one date. A time with nothing free is not listed.',
                'Time',
                list: true,
                params: ['type', 'date', 'people'],
            ),
            '/availability' => self::get(
                'availability',
                'What is free at one exact time, grouped by area.',
                'AreaAvailability',
                list: true,
                params: ['type', 'date', 'time', 'people'],
            ),
            '/booking-url' => self::get(
                'bookingUrl',
                'Where to send the visitor once your form has their choice.',
                'BookingUrl',
                params: ['type', 'date', 'time', 'people'],
            ),
        ];
    }

    /**
     * @param  array<int, string>  $params
     * @return array<string, mixed>
     */
    private static function get(
        string $operationId,
        string $summary,
        string $schema,
        bool $list = false,
        array $params = [],
    ): array {
        $data = $list
            ? ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$schema]]
            : ['$ref' => '#/components/schemas/'.$schema];

        return ['get' => [
            'operationId' => $operationId,
            'summary' => $summary,
            'parameters' => array_map(
                static fn (string $name): array => ['$ref' => '#/components/parameters/'.$name],
                $params,
            ),
            'responses' => [
                '200' => [
                    'description' => 'OK',
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => ['data' => $data, 'meta' => ['type' => 'object']],
                        'required' => ['data'],
                    ]]],
                ],
                '401' => ['$ref' => '#/components/responses/Error'],
                '404' => ['$ref' => '#/components/responses/Error'],
                '422' => ['$ref' => '#/components/responses/Error'],
                '429' => ['$ref' => '#/components/responses/Error'],
            ],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function param(string $name, string $in, string $description, string $type): array
    {
        return [
            'name' => $name,
            'in' => $in,
            'required' => false,
            'description' => $description,
            'schema' => ['type' => $type],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemas(): array
    {
        return [
            'Venue' => self::object([
                'slug' => 'string',
                'name' => 'string',
                'timezone' => 'string',
                'currency' => 'string',
                'city' => 'string',
                'country' => 'string',
                'phone' => 'string',
                'booking_url' => 'string',
            ]),
            'BookingType' => self::object([
                'slug' => 'string',
                'name' => 'string',
                'resource_type' => 'string',
                'booking_mode' => 'string',
                'from_price_cents' => 'integer',
                'from_price' => 'string',
                'max_advance_days' => 'integer',
                'min_lead_time_minutes' => 'integer',
            ]),
            'Day' => self::object([
                'date' => 'string',
                'status' => 'string',
            ]),
            'Time' => self::object([
                'time' => 'string',
                'starts_at' => 'string',
                'ends_at' => 'string',
                'available' => 'integer',
                'from_price_cents' => 'integer',
                'from_price' => 'string',
            ]),
            'AreaAvailability' => [
                'type' => 'object',
                'properties' => [
                    'area' => self::object(['name' => 'string', 'description' => 'string']),
                    'available' => ['type' => 'integer'],
                    'from_price_cents' => ['type' => 'integer'],
                    'from_price' => ['type' => 'string'],
                    'resources' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/Spot'],
                    ],
                ],
            ],
            'Spot' => self::object([
                'label' => 'string',
                'contents' => 'string',
                'capacity_min' => 'integer',
                'capacity_max' => 'integer',
                'price_cents' => 'integer',
                'price' => 'string',
                'starts_at' => 'string',
                'ends_at' => 'string',
                'minutes' => 'integer',
            ]),
            'BookingUrl' => self::object(['url' => 'string']),
        ];
    }

    /**
     * @param  array<string, string>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => array_map(
                static fn (string $type): array => ['type' => $type],
                $properties,
            ),
        ];
    }
}
