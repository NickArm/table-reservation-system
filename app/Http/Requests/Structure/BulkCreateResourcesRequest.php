<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

final class BulkCreateResourcesRequest extends FormRequest
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
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'prefix' => ['nullable', 'string', 'max:8'],
            'from' => ['required', 'integer', 'min:1', 'max:999'],
            'to' => ['required', 'integer', 'min:1', 'max:999'],
            'template_id' => ['nullable', 'integer', 'exists:resource_templates,id'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.max' => 'Create at most 999 at a time.',
        ];
    }

    /**
     * Null means "whatever the booking type charges" — the common case, and
     * the reason this is an override rather than a required field.
     */
    public function priceCents(): ?int
    {
        return $this->filled('price')
            ? Money::toCents((string) $this->string('price'))
            : null;
    }
}
