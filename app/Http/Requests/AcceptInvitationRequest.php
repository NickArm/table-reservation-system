<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The same bar as the rest of the application, including the
             * breach list: this is the password on an account that can read
             * every guest the venue has ever had.
             */
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
