<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing one of the platform's legal texts (SPEC §12.2).
 */
final class PlatformDocumentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * A name a person would quote, not a number we increment. It ends
             * up inside an agreement an account accepted, so it has to be
             * short, stable and legible: "1.0", "2026-10", "2.1".
             */
            'version' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-]+$/'],

            // No maximum worth setting: a terms page is as long as the lawyer
            // makes it, and the column is TEXT.
            'content' => ['required', 'string'],

            'change_summary' => ['nullable', 'string', 'max:255'],
            'needs_reacceptance' => ['boolean'],
            'publish' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.regex' => 'Letters, numbers, dots and dashes only — this string '
                .'ends up in an agreement somebody accepted.',
        ];
    }
}
