<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * The times offered on one date (SPEC §15.2).
 */
final class TimesRequest extends AvailabilityRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'date' => ['required', 'string', 'size:10'],
        ];
    }

    public function day(): string
    {
        return $this->localDate('date');
    }
}
