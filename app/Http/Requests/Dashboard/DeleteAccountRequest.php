<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\TenantRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirming an account deletion (SPEC §29.2).
 */
final class DeleteAccountRequest extends FormRequest
{
    /**
     * Owners only.
     *
     * Staff run the floor; ending the business is not one of the things they
     * do (§2.3). Authorised here rather than by a middleware, because this is
     * the only route in the dashboard that needs the distinction and a
     * middleware for one route is a thing somebody later applies to five.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $tenant = app(TenantContext::class)->get();

        if (! $user instanceof User || $tenant === null) {
            return false;
        }

        return $user->roleIn($tenant) === TenantRole::Owner;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->get();

        return [
            /*
             * The venue's name, typed out. Not a checkbox and not a second
             * "are you sure" dialog: typing is the only confirmation that
             * cannot be clicked through by muscle memory, and this is the one
             * action in the product with no undo from inside it.
             */
            'confirm' => ['required', 'string', Rule::in([$tenant?->name])],

            // The password as well, because the screen is reachable from a
            // laptop somebody left open.
            'password' => ['required', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.in' => 'Type the venue name exactly as it appears above.',
            'password.current_password' => 'That is not your password.',
        ];
    }
}
