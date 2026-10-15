<?php

declare(strict_types=1);

namespace App\Http\Requests\Reservations;

use App\Enums\Channel;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A booking the venue is entering itself (SPEC §13.1).
 *
 * Looser than the public form on purpose. Online, an email address is required
 * because it is where the voucher goes; on the telephone it frequently does
 * not exist, and forcing one means staff typing `noemail@x.gr`, which bounces
 * every confirmation and poisons the guest list.
 *
 * What stays strict is the phone number, because that is how the venue reaches
 * somebody who has not turned up.
 */
final class StoreManualReservationRequest extends FormRequest
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
            'resource' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'people' => ['required', 'integer', 'min:1', 'max:60'],

            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'calling_code' => ['required', 'string', Rule::in(array_keys(PhoneNumber::callingCodes()))],
            'phone' => ['required', 'string', 'max:30'],

            'notes' => ['nullable', 'string', 'max:500'],
            'channel' => ['nullable', Rule::enum(Channel::class)],

            // Blank means "whatever the price list says".
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'paid' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'A phone number, so you can reach them if they are late.',
            'resource.required' => 'Choose which table or sunbed this is for.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! PhoneNumber::isValid($this->input('phone'), (string) $this->string('calling_code'))) {
                    $validator->errors()->add('phone', 'That does not look like a phone number.');
                }
            },
        ];
    }

    public function normalizedPhone(): ?string
    {
        return PhoneNumber::normalize($this->input('phone'), (string) $this->string('calling_code'));
    }

    public function normalizedEmail(): ?string
    {
        $email = mb_strtolower(trim((string) $this->string('email')));

        return $email === '' ? null : $email;
    }

    public function channel(): Channel
    {
        return Channel::tryFrom((string) $this->string('channel')) ?? Channel::Phone;
    }

    /**
     * Null means the price book decides. Zero means the owner said it is free,
     * which is a different thing and has to survive the journey.
     */
    public function amountCents(): ?int
    {
        $amount = $this->input('amount');

        if ($amount === null || $amount === '') {
            return null;
        }

        return Money::toCents((string) $amount);
    }
}
