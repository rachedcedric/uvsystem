<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('7#########'),
            'type' => fake()->randomElement(['standard', 'premium']),
            'debt_limit' => fake()->randomElement([0, 100000, 250000, 500000, 1000000]),
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the agent is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the agent is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    /**
     * Indicate that the agent is premium.
     */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'premium',
            'debt_limit' => 2000000,
        ]);
    }

    /**
     * Indicate unlimited debt.
     */
    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => [
            'debt_limit' => 0,
        ]);
    }
}
