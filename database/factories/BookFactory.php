<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Book>
 */
class BookFactory extends Factory
{
    protected $model = Book::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence(3);
        $slug = Str::slug($title);
        $author = User::factory()->create(['account_type' => 'author']);

        return [
            'title' => $title,
            'sub_title' => $this->faker->sentence(5),
            'slug' => $slug,
            'author_id' => $author->id,
            'description' => $this->faker->paragraph(),
            'isbn' => $this->faker->isbn13(),
            'table_of_contents' => json_encode([]),
            'tags' => json_encode([]),
            'category' => json_encode([]),
            'genres' => json_encode([]),
            'publication_date' => now(),
            'language' => json_encode(['English']),
            'cover_image' => json_encode([]),
            'format' => 'pdf',
            'files' => json_encode([]),
            'target_audience' => json_encode([]),
            'pricing' => json_encode(['actual_price' => 9.99, 'discounted_price' => 7.99]),
            'actual_price' => 9.99,
            'discounted_price' => 7.99,
            'currency' => 'USD',
            'availability' => '{}',
            'views_count' => 0,
            'file_size' => '1MB',
            'archived' => false,
            'deleted' => false,
            'drm_info' => null,
            'meta_data' => json_encode(['pages' => 100]),
            'publisher' => $this->faker->company(),
        ];
    }
}
