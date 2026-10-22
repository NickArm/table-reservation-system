<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VenueDocumentType;
use App\Models\LegalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocument>
 */
class LegalDocumentFactory extends Factory
{
    protected $model = LegalDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => VenueDocumentType::CancellationPolicy,
            'version' => 1,
            'content' => 'Cancel at least 24 hours before and we refund in full.',
            'published_at' => now(),
            'is_current' => true,
        ];
    }

    public function of(VenueDocumentType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function superseded(): static
    {
        return $this->state(fn (): array => ['is_current' => false]);
    }
}
