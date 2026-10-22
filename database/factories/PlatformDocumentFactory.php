<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlatformDocumentType;
use App\Models\PlatformDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformDocument>
 */
class PlatformDocumentFactory extends Factory
{
    protected $model = PlatformDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => PlatformDocumentType::Terms,
            'version' => '1.0',
            'content' => "## Terms\n\nThe venue sells to its own guests. We provide software.",
            'change_summary' => null,
            'needs_reacceptance' => false,
            'published_at' => now(),
            'is_current' => true,
        ];
    }

    public function of(PlatformDocumentType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'published_at' => null,
            'is_current' => false,
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn (): array => ['is_current' => false]);
    }
}
