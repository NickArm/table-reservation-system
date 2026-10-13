<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use App\Enums\PriceRuleType;
use App\Enums\StructureScope;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePriceRuleRequest extends FormRequest
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
            'target' => ['required', 'string', 'regex:/^(booking_type|area|resource):[0-9]+$/'],
            'name' => ['nullable', 'string', 'max:60'],

            'price_type' => ['required', Rule::enum(PriceRuleType::class)],
            'amount' => ['required_if:price_type,fixed', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'percent' => ['required_if:price_type,percent_modifier', 'nullable', 'integer', 'min:-90', 'max:500'],

            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:0,6'],

            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],

            'time_from' => ['nullable', 'date_format:H:i', 'required_with:time_to'],
            'time_to' => ['nullable', 'date_format:H:i', 'required_with:time_from'],

            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target.regex' => 'Choose what this price is for.',
            'amount.required_if' => 'Give the price.',
            'percent.required_if' => 'Say how much more or less.',
            'date_to.after_or_equal' => 'The last day cannot come before the first.',
        ];
    }

    public function scope(): StructureScope
    {
        return StructureScope::from($this->target()[0]);
    }

    public function scopeId(): int
    {
        return (int) $this->target()[1];
    }

    public function type(): PriceRuleType
    {
        return PriceRuleType::from((string) $this->string('price_type'));
    }

    /**
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $type = $this->type();

        return [
            'scope' => $this->scope(),
            'scope_id' => $this->scopeId(),
            'name' => $this->input('name'),
            'price_type' => $type,
            'amount_cents' => $type === PriceRuleType::Fixed
                ? Money::toCents((string) $this->string('amount'))
                : null,
            'percent' => $type === PriceRuleType::PercentModifier
                ? (int) $this->integer('percent')
                : null,
            'days_of_week' => $this->filled('days_of_week')
                ? array_map('intval', (array) $this->input('days_of_week'))
                : null,
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'time_from' => $this->input('time_from'),
            'time_to' => $this->input('time_to'),
            'priority' => (int) $this->integer('priority', 10),
            'is_active' => true,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function target(): array
    {
        [$scope, $id] = explode(':', (string) $this->string('target'), 2);

        return [$scope, $id];
    }
}
