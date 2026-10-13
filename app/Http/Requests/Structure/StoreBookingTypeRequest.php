<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use App\Enums\BookingMode;
use App\Enums\PaymentRequirement;
use App\Enums\ResourceType;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBookingTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'resource_type' => ['required', Rule::enum(ResourceType::class)],
            'booking_mode' => ['required', Rule::enum(BookingMode::class)],
            'payment_requirement' => ['required', Rule::enum(PaymentRequirement::class)],
            'deposit_label' => ['nullable', 'string', 'max:120'],
            'default_price' => ['required', 'numeric', 'min:0', 'max:9999'],
            'min_lead_time_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
        ];
    }

    /**
     * Prices are entered in euros and stored in cents, so that no total ever
     * depends on floating point arithmetic (SPEC §31.2).
     */
    public function priceCents(): int
    {
        return Money::toCents((string) $this->string('default_price'));
    }
}
