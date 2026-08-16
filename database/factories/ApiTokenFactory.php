<?php

namespace Database\Factories;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'token_hash' => ApiToken::hashToken('lf_'.Str::random(48)),
            'abilities' => 'links:read,links:write,analytics:read',
        ];
    }

    public function withPlaintext(string $plaintext): static
    {
        return $this->state(fn () => ['token_hash' => ApiToken::hashToken($plaintext)]);
    }

    public function readOnly(): static
    {
        return $this->state(fn () => ['abilities' => 'links:read,analytics:read']);
    }
}
