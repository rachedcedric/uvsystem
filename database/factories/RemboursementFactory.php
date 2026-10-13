<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Remboursement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Remboursement>
 */
class RemboursementFactory extends Factory
{
    protected $model = Remboursement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'amount' => fake()->randomFloat(2, 5000, 100000),
            'payment_method' => fake()->randomElement(['cash', 'mobile_money', 'bank_transfer', 'cheque']),
            'payment_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate cash payment.
     */
    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'cash',
        ]);
    }

    /**
     * Indicate mobile money payment.
     */
    public function mobileMoney(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'mobile_money',
        ]);
    }
}
