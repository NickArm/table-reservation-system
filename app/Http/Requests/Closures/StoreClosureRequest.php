<?php

declare(strict_types=1);

namespace App\Http\Requests\Closures;

use App\Enums\StructureScope;
use Illuminate\Foundation\Http\FormRequest;

final class StoreClosureRequest extends FormRequest
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
            // One field rather than two, so the form works without a line
            // of JavaScript keeping them in step.
            'target' => ['required', 'string', 'regex:/^(booking_type|area|resource):[0-9]+$/'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'time_from' => ['nullable', 'date_format:H:i', 'required_with:time_to'],
            'time_to' => ['nullable', 'date_format:H:i', 'required_with:time_from'],
            'reason' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target.regex' => 'Choose what you are closing.',
            'date_to.after_or_equal' => 'The last day cannot come before the first.',
            'time_from.required_with' => 'Give both a start and an end time, or neither.',
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

    /**
     * @return array{0: string, 1: string}
     */
    private function target(): array
    {
        [$scope, $id] = explode(':', (string) $this->string('target'), 2);

        return [$scope, $id];
    }
}
