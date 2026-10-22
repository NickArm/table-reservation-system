<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'venue_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],

            /*
             * Agreeing to the terms is a condition of having an account, so
             * it is validated here rather than nudged in the view. `accepted`
             * rejects an unticked box as well as a missing one, which an
             * `boolean` rule would let through (SPEC §12.2).
             */
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => 'You have to agree to the terms and the privacy '
                .'policy to open an account.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'venue_name' => 'venue name',
            'terms' => 'terms',
        ];
    }
}
