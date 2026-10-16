<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\BookingType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The query parameters every availability endpoint shares (SPEC §15.2).
 *
 * One request class for all four reads rather than four nearly identical
 * ones. Each endpoint declares which parameters it needs by name, because
 * `date` is required when asking about one day and meaningless when asking
 * about a month, and a caller who gets that wrong deserves to be told which
 * parameter is missing rather than handed an empty list.
 */
abstract class AvailabilityRequest extends FormRequest
{
    /**
     * Public availability: the same information anyone sees on the booking
     * page. The token middleware or the slug in the path has already decided
     * who is asking.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'max:191'],
            'people' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function partySize(): int
    {
        return max(1, (int) ($this->integer('people') ?: 1));
    }

    /**
     * The booking type asked for, or the account's first one.
     *
     * Falling back is deliberate: most venues have exactly one, and making
     * every caller name it would be ceremony. A named type that does not
     * exist is a 404 rather than a silent fallback, because answering about
     * the wrong thing is worse than answering nothing.
     */
    public function bookingType(): BookingType
    {
        $slug = (string) $this->string('type');

        $query = BookingType::query()->active();

        if ($slug !== '') {
            $query->where('slug', $slug);
        }

        $bookingType = $query->first();

        abort_if(! $bookingType instanceof BookingType, 404, 'No such booking type.');

        return $bookingType;
    }

    /**
     * A local date in the venue's own terms.
     *
     * Validated as a format rather than with Laravel's `date` rule, which
     * accepts "next tuesday" and a dozen other things no caller means and no
     * caller can rely on staying accepted.
     */
    protected function localDate(string $key): string
    {
        $value = (string) $this->string($key);

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $key => 'Use YYYY-MM-DD, for example 2026-07-04.',
            ]);
        }
    }
}
