<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A whole month in one call, for colouring a date picker (SPEC §15.2).
 */
final class DaysRequest extends AvailabilityRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'month' => ['nullable', 'string', 'size:7'],
        ];
    }

    /**
     * The month asked for as YYYY-MM, defaulting to the current one.
     */
    public function month(): string
    {
        $value = (string) $this->string('month');

        if ($value === '') {
            return CarbonImmutable::now()->format('Y-m');
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m', $value)->format('Y-m');
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'month' => 'Use YYYY-MM, for example 2026-07.',
            ]);
        }
    }
}
