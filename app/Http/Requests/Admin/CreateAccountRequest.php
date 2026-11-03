<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening an account on somebody's behalf (SPEC §4.1).
 *
 * No password field anywhere, on purpose: the owner chooses their own
 * through the invitation. A form that let an operator type somebody else's
 * password would be a form that produces passwords operators remember.
 */
final class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'venue_name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],

            /*
             * Unique across users, not across this account: an address that
             * already belongs to somebody is a person who already has a way
             * in, and quietly making a second account for them is how one
             * human ends up with two logins and rings up about the empty one.
             */
            /*
             * `email:filter`, the same rule the sign-up form uses. Not
             * `dns`: that resolves MX records on submit, which turns a form
             * into a network call and rejects a perfectly good address
             * whenever the host's resolver is having a bad minute.
             */
            'owner_email' => ['required', 'string', 'email:filter', 'max:190', Rule::unique('users', 'email')],

            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],

            'send_invitation' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_email.unique' => 'Somebody already signs in with that address. '
                .'Find them in the accounts list rather than making a second one.',
        ];
    }
}
