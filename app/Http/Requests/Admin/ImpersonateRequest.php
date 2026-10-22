<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Signing in as a venue (SPEC §2.1).
 *
 * Every action taken while impersonating looks exactly like the owner doing
 * it in every other table. This sentence is the only thing that will later
 * say otherwise, so it is not optional.
 */
final class ImpersonateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you need to be in this account. '
                .'It goes in the log with your name on it.',
        ];
    }
}
