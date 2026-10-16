<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiRequest;
use App\Models\ApiToken;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiRequest>
 */
class ApiRequestFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = ApiRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),

            // Null is a real state, not a shortcut: the log outlives the
            // token it recorded, so the account's usage history has no hole
            // after a regenerate.
            'api_token_id' => null,

            'method' => 'GET',
            'endpoint' => 'api/v1/times',
            'status' => 200,
            'duration_ms' => fake()->numberBetween(4, 90),
            'occurred_at' => now(),
        ];
    }

    public function forToken(ApiToken $token): static
    {
        return $this->state(fn (): array => ['api_token_id' => $token->getKey()]);
    }

    public function rejected(int $status = 422): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
