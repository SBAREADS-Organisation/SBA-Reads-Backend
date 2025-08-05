<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'reference' => $this->faker->unique()->uuid(),
            'payment_intent_id' => 'pi_' . $this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'payment_client_secret' => 'pi_' . $this->faker->unique()->regexify('[A-Za-z0-9]{24}') . '_secret_' . $this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'status' => $this->faker->randomElement(['pending', 'succeeded', 'failed']),
            'currency' => 'usd',
            'type' => $this->faker->randomElement(['purchase', 'earning', 'payout', 'refund']),
            'amount' => $this->faker->randomFloat(2, 1, 1000),
            'description' => $this->faker->sentence(),
            'payment_provider' => 'stripe',
            'purchased_by' => User::factory(),
            'direction' => $this->faker->randomElement(['credit', 'debit']), // Fixed: use valid values
            'available_at' => $this->faker->optional()->dateTimeBetween('now', '+1 month'),
            'payout_data' => null,
            'purpose_type' => 'order',
            'purpose_id' => $this->faker->uuid(),
            'meta_data' => null,
        ];
    }

    /**
     * Configure the factory to create a transaction for a specific order.
     */
    public function forOrder(\App\Models\Order $order): static
    {
        return $this->state([
            'purpose_type' => 'order',
            'purpose_id' => $order->id,
        ]);
    }
}
