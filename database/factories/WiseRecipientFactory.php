<?php

namespace Owise\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Owise\Models\WiseRecipient;

/**
 * @extends Factory<WiseRecipient>
 */
class WiseRecipientFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<WiseRecipient>
     */
    protected $model = WiseRecipient::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payable_type' => 'App\Models\User',
            'payable_id' => 1,
            'profile_name' => 'default',
            'profile_id' => 101,
            'wise_recipient_id' => $this->faker->unique()->numberBetween(10000000, 99999999),
            'currency' => 'EUR',
            'type' => 'iban',
            'holder_name' => $this->faker->name(),
            'summary' => 'IBAN ending in 1234',
            'country' => 'DE',
            'is_default' => false,
            'active' => true,
        ];
    }

    /**
     * Mark the recipient as the payable's default for its currency.
     *
     * @return $this
     */
    public function asDefault(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
