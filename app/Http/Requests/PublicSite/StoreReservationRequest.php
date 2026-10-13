<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicSite;

use App\Models\BookingType;
use App\Models\Reservation;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreReservationRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'calling_code' => ['required', 'string', Rule::in(array_keys(PhoneNumber::callingCodes()))],
            'phone' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'terms' => ['accepted'],
            'marketing' => ['nullable', 'boolean'],

            // A field no human sees and every crude bot fills in (§24.6).
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => 'Please agree to the terms and the cancellation policy.',
            'phone.required' => 'We need a phone number in case we have to reach you.',
            'website.prohibited' => 'Something went wrong. Please try again.',
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

                    return;
                }

                $this->checkDailyLimit($validator);
            },
        ];
    }

    public function normalizedPhone(): ?string
    {
        return PhoneNumber::normalize($this->input('phone'), (string) $this->string('calling_code'));
    }

    public function normalizedEmail(): string
    {
        return mb_strtolower(trim((string) $this->string('email')));
    }

    /**
     * One guest cannot quietly take the whole front row (SPEC §24.6).
     */
    private function checkDailyLimit(Validator $validator): void
    {
        $bookingType = $this->route('hold')?->bookingType()->first();

        if (! $bookingType instanceof BookingType) {
            return;
        }

        $limit = (int) $bookingType->max_per_email_24h;

        if ($limit <= 0) {
            return;
        }

        $recent = Reservation::query()
            ->blocking()
            ->where('booking_type_id', $bookingType->id)
            ->where('customer_email', $this->normalizedEmail())
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recent >= $limit) {
            $validator->errors()->add('email', sprintf(
                'You have reached the limit of %d %s a day. Please call us for a larger booking.',
                $limit,
                $limit === 1 ? 'reservation' : 'reservations',
            ));
        }
    }
}
