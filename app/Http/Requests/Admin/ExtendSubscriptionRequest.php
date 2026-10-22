<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Extending a subscription by hand (SPEC §4.1).
 */
final class ExtendSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            // Tomorrow at the earliest. "Extending" to today or to a date
            // already gone is not an extension, and the action refuses it
            // anyway — this is so the refusal arrives on the form rather
            // than as an error message.
            'until' => ['required', 'date', 'after:today'],

            /*
             * Required, and long enough to be a sentence. An unexplained free
             * month is indistinguishable from a mistake six months later, and
             * "ok" in the box is the same as nothing. The action throws
             * without one; this is where the person is told why.
             */
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'until.after' => 'Pick a date after today — this moves a period forward, '
                .'it does not shorten one.',
            'reason.required' => 'Say why. This goes in the log with your name on it.',
        ];
    }
}
