<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiTokenMode;
use App\Models\ApiToken;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = ApiToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'mode' => ApiTokenMode::Live,
            ...ApiToken::mint(ApiTokenMode::Live),
        ];
    }

    public function test(): static
    {
        return $this->state(fn (): array => [
            'mode' => ApiTokenMode::Test,
            ...ApiToken::mint(ApiTokenMode::Test),
        ]);
    }

    /**
     * A token with a known value, for a test that has to send it.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (): array => [
            'token' => $token,
            'token_hash' => ApiToken::hash($token),
            'prefix' => substr($token, 0, 12),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function exposed(): static
    {
        return $this->state(fn (): array => [
            'exposed_at' => now(),
            'last_origin' => 'https://example.test',
        ]);
    }
}
