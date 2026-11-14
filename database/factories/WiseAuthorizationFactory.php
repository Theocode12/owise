<?php

namespace Owise\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Owise\Enums\WiseAuthorizationStatus;
use Owise\Models\WiseAuthorization;

/**
 * @extends Factory<WiseAuthorization>
 */
class WiseAuthorizationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<WiseAuthorization>
     */
    protected $model = WiseAuthorization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'registration_code' => Str::random(32),
            'language' => 'EN',
            'wise_user_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'scope' => 'transfers',
            'status' => WiseAuthorizationStatus::Active->value,
            'revoked_at' => null,
        ];
    }

    /**
     * Mark the authorization as revoked.
     *
     * @return $this
     */
    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => WiseAuthorizationStatus::Revoked->value,
            'revoked_at' => now(),
        ]);
    }
}
