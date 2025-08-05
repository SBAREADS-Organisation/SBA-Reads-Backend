<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $book = Book::factory()->create();
        $author = User::factory()->create();

        return [
            'book_id' => $book->id,
            'author_id' => $author->id,
            'quantity' => $this->faker->numberBetween(1, 5),
            'unit_price' => $this->faker->randomFloat(2, 5, 100),
            'total_price' => $this->faker->randomFloat(2, 10, 500),
            'author_payout_amount' => $this->faker->randomFloat(2, 5, 200),
            'platform_fee_amount' => $this->faker->randomFloat(2, 1, 50),
            'payout_status' => $this->faker->randomElement(['pending', 'initiated', 'completed', 'failed']),
        ];
    }
}
