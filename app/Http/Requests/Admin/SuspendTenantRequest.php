<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Switching an account off by hand (SPEC §2.1).
 *
 * Nothing automatic can undo a suspension, so the reason is the only thing
 * that will later explain it. Required for that alone.
 */
final class SuspendTenantRequest extends FormRequest
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
            'reason.required' => 'Say why. A suspension nobody can explain is '
                .'indistinguishable from a mistake.',
        ];
    }
}
