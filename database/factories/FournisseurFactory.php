<?php

namespace Database\Factories;

use App\Models\Fournisseur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fournisseur>
 */
class FournisseurFactory extends Factory
{
    protected $model = Fournisseur::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => fake()->randomElement(['BANQUE', 'AGENT_PREMIUM']),
            'contact' => fake()->phoneNumber(),
            'email' => fake()->email(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the fournisseur is a bank.
     */
    public function banque(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'banque',
        ]);
    }

    /**
     * Indicate that the fournisseur is a premium agent.
     */
    public function agentPremium(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'agent_premium',
            'contact' => fake()->numerify('7#########'),
        ]);
    }
}
