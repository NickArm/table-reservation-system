<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * Where to send a visitor once a custom form has their choice (SPEC §15.2).
 *
 * Date and time are optional here, unlike the availability reads: a form may
 * know only that someone wants a table for four, and the booking page is
 * perfectly able to ask for the rest.
 */
final class BookingUrlRequest extends AvailabilityRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'date' => ['nullable', 'string', 'size:10'],
            'time' => ['nullable', 'string', 'size:5'],
        ];
    }
}
