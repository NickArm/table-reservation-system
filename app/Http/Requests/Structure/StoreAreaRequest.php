<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use App\Enums\Occupancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAreaRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:500'],

            // All three are overrides. Left empty, the area follows the
            // booking type, which is what most of them do (SPEC §5.1).
            'occupancy' => ['nullable', Rule::enum(Occupancy::class)],
            'max_stay_minutes' => ['nullable', 'integer', 'min:15', 'max:1440'],
            'turnover_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForArea(): array
    {
        return [
            'name' => (string) $this->string('name'),
            'description' => $this->input('description'),
            'occupancy' => $this->filled('occupancy')
                ? Occupancy::from((string) $this->string('occupancy'))
                : null,
            'max_stay_minutes' => $this->filled('max_stay_minutes')
                ? (int) $this->integer('max_stay_minutes')
                : null,
            'turnover_buffer_minutes' => $this->filled('turnover_buffer_minutes')
                ? (int) $this->integer('turnover_buffer_minutes')
                : null,
        ];
    }
}
