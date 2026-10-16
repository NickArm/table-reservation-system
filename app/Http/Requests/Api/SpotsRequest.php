<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Availability\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * What is free at one exact time (SPEC §15.2).
 */
final class SpotsRequest extends AvailabilityRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'date' => ['required', 'string', 'size:10'],
            'time' => ['required', 'string', 'size:5'],
        ];
    }

    public function day(): string
    {
        return $this->localDate('date');
    }

    /**
     * The local date and time resolved to an instant.
     *
     * Through LocalTime rather than a plain parse, because the hour a guest
     * typed may not exist on the morning the clocks go forward, and may exist
     * twice on the morning they go back (SPEC §3.5).
     */
    public function startsAt(string $timezone): CarbonImmutable
    {
        $time = (string) $this->string('time');

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw ValidationException::withMessages([
                'time' => 'Use HH:MM on a 24-hour clock, for example 10:30.',
            ]);
        }

        return LocalTime::normalized($this->day(), $time, $timezone);
    }
}
