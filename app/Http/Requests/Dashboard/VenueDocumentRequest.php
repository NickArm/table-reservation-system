<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A venue saving its terms or cancellation policy (SPEC §12.1).
 */
final class VenueDocumentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * A minimum rather than just `required`. An empty-ish policy is
             * worse than none at all: the checkout checkbox would be asking a
             * guest to agree to two words, and the venue would be relying on
             * it. With nothing saved the booking page falls back sensibly.
             */
            'content' => ['required', 'string', 'min:40', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.min' => 'A sentence or two at least — your guests agree to this '
                .'at checkout, and it is printed on their confirmation.',
        ];
    }
}
