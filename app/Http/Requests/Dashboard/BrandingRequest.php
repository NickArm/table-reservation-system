<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\VenueImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Logo, hero image and the one colour (SPEC §5.5).
 */
final class BrandingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        $kilobytes = (int) config('media.max_upload_kilobytes');

        return [
            /*
             * Hex, six digits, with the hash. Validated because it goes
             * straight into a `style` attribute on the public page — a colour
             * field that accepted arbitrary text would be a way to write CSS
             * into somebody else's booking page.
             */
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            // How dark the scrim over the hero is, so white text stays
            // readable on a bright photograph.
            'hero_overlay' => ['required', 'integer', 'min:0', 'max:80'],

            /*
             * `image` and an explicit extension list, deliberately both.
             *
             * SVG is the one worth spelling out: it is a document that can
             * carry script, and served from our own domain that is stored
             * cross-site scripting rather than a picture. `image` alone has
             * historically let some through, so the allow-list is the real
             * guard and `image` is the cheap first pass.
             */
            'logo' => [
                'nullable', 'file', 'image',
                Rule::file()->extensions(['jpg', 'jpeg', 'png', 'webp'])->max($kilobytes),
            ],
            'hero' => [
                'nullable', 'file', 'image',
                Rule::file()->extensions(['jpg', 'jpeg', 'png', 'webp'])->max($kilobytes),
            ],

            'remove' => ['array'],
            'remove.*' => [Rule::enum(VenueImage::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accent_color.regex' => 'Use a colour like #0e6e6e.',
            'logo.extensions' => 'Pick a JPG, PNG or WebP. An SVG is a document '
                .'rather than a picture, so we do not take them.',
            'hero.extensions' => 'Pick a JPG, PNG or WebP.',
            'logo.max' => 'That file is too big. Anything up to 8 MB is fine — '
                .'a photo straight off a phone usually is.',
            'hero.max' => 'That file is too big. Anything up to 8 MB is fine — '
                .'a photo straight off a phone usually is.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function removals(): array
    {
        /** @var array<int, string> $remove */
        $remove = $this->input('remove', []);

        return array_values(array_filter($remove, 'is_string'));
    }
}
