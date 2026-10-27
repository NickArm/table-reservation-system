<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\VenueCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The venue's public profile (SPEC §33.1).
 *
 * Almost everything here is optional, and that is the point: none of it is
 * needed to take a booking. A thin profile only costs search visibility, so
 * refusing to save a form because the postcode is missing would be charging
 * an owner for something they have not asked for.
 *
 * The two that are not optional are the ones a guest reads on the
 * confirmation: the name of the place, and its timezone — which every
 * reservation in the system is stored against (§3.5).
 */
final class VenueProfileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            'venue_category' => ['nullable', Rule::enum(VenueCategory::class)],
            'cuisine' => ['nullable', 'string', 'max:80'],

            // Google's own vocabulary for this field: one to four currency
            // symbols. Free text here would end up in the structured data.
            'price_range' => ['nullable', 'string', Rule::in(['€', '€€', '€€€', '€€€€'])],

            'address' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'region' => ['nullable', 'string', 'max:100'],

            // Two letters, which is what schema.org and every map expects.
            'country' => ['nullable', 'string', 'size:2'],

            /*
             * Both or neither. Half a coordinate puts a pin in the Atlantic,
             * and a map with a wrong pin is worse for a venue than a map with
             * no pin at all.
             */
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'phone' => ['nullable', 'string', 'max:32'],
            'public_email' => ['nullable', 'email', 'max:160'],
            'website_url' => ['nullable', 'url', 'max:200'],

            'is_indexable' => ['boolean'],

            // Google truncates past roughly these lengths, so the limits are
            // the useful ones rather than the database's.
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required_with' => 'Give both numbers or neither — half a '
                .'coordinate puts the pin in the sea.',
            'longitude.required_with' => 'Give both numbers or neither — half a '
                .'coordinate puts the pin in the sea.',
            'country.size' => 'Two letters, like GR.',
            'price_range.in' => 'One to four euro signs.',
        ];
    }
}
