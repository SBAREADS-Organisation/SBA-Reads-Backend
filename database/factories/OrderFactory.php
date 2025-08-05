<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use App\Models\Address;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'total_amount' => $this->faker->randomFloat(2, 10, 1000),
            'platform_fee_amount' => $this->faker->randomFloat(2, 1, 100),
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'cancelled']),
            'payout_status' => $this->faker->randomElement(['initiated', 'completed', 'failed']),
            'transaction_id' => Transaction::factory(),
            'tracking_number' => Order::generateTrackingNumber(),
            'delivery_address_id' => Address::factory(),
            'delivered_at' => $this->faker->optional()->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Configure the factory to create a transaction for the order.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Order $order) {
            // Only create a new transaction if one wasn't explicitly provided
            if (!$order->transaction_id) {
                $transaction = Transaction::factory()->create([
                    'purpose_type' => 'order',
                    'purpose_id' => $order->id,
                ]);
                $order->update(['transaction_id' => $transaction->id]);
            }
        });
    }
}
