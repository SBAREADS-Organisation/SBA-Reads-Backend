<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles for testing
        Role::create(['name' => 'admin', 'guard_name' => 'api']);
        Role::create(['name' => 'superadmin', 'guard_name' => 'api']);
        Role::create(['name' => 'author', 'guard_name' => 'api']);
        Role::create(['name' => 'user', 'guard_name' => 'api']);
    }

    /** @test */
    public function author_can_update_their_own_book()
    {
        // Create an author user
        $author = User::factory()->create([
            'account_type' => 'author',
        ]);

        // Create a book for the author
        $book = Book::factory()->create([
            'author_id' => $author->id,
            'title' => 'Original Title',
            'description' => 'Original Description',
        ]);

        // Create categories
        $category1 = Category::factory()->create();
        $category2 = Category::factory()->create();

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Update the book
        $updateData = [
            'title' => 'Updated Title',
            'description' => 'Updated Description',
            'categories' => [$category1->id, $category2->id],
            'tags' => ['fiction', 'adventure'],
            'actual_price' => 19.99,
        ];

        $response = $this->putJson("/api/books/{$book->id}", $updateData);

        // Assert successful response
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'description',
                    'categories',
                    'tags',
                    'actual_price',
                ],
                'message'
            ]);

        // Assert the book was updated in database
        $book->refresh();
        $this->assertEquals('Updated Title', $book->title);
        $this->assertEquals('Updated Description', $book->description);
        $this->assertEquals(19.99, $book->actual_price);
        $this->assertEquals(['fiction', 'adventure'], $book->tags);

        // Assert categories were synced
        $this->assertTrue($book->categories->contains($category1));
        $this->assertTrue($book->categories->contains($category2));
    }

    /** @test */
    public function admin_can_update_any_book()
    {
        // Create an admin user
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Create an author and their book
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create([
            'author_id' => $author->id,
            'title' => 'Original Title',
        ]);

        // Authenticate as admin
        Sanctum::actingAs($admin);

        // Update the book
        $updateData = [
            'title' => 'Admin Updated Title',
            'description' => 'Admin updated description',
        ];

        $response = $this->putJson("/api/books/{$book->id}", $updateData);

        // Assert successful response
        $response->assertStatus(200);

        // Assert the book was updated
        $book->refresh();
        $this->assertEquals('Admin Updated Title', $book->title);
        $this->assertEquals('Admin updated description', $book->description);
    }

    /** @test */
    public function user_cannot_update_others_books()
    {
        // Create two authors
        $author1 = User::factory()->create(['account_type' => 'author']);
        $author2 = User::factory()->create(['account_type' => 'author']);

        // Create a book for author1
        $book = Book::factory()->create([
            'author_id' => $author1->id,
        ]);

        // Authenticate as author2
        Sanctum::actingAs($author2);

        // Try to update author1's book
        $updateData = [
            'title' => 'Unauthorized Update',
        ];

        $response = $this->putJson("/api/books/{$book->id}", $updateData);

        // Assert unauthorized response
        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Unauthorized. You can only update your own books.'
            ]);
    }

    /** @test */
    public function book_update_validates_input()
    {
        // Create an author user
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create(['author_id' => $author->id]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Try to update with invalid data
        $invalidData = [
            'title' => '', // Empty title should fail
            'actual_price' => -10, // Negative price should fail
            'categories' => [999], // Non-existent category should fail
        ];

        $response = $this->putJson("/api/books/{$book->id}", $invalidData);

        // Assert validation error
        $response->assertStatus(400)
            ->assertJsonStructure([
                'data' => [
                    'title',
                    'actual_price',
                    'categories.0',
                ]
            ]);
    }

    /** @test */
    public function author_can_toggle_their_book_visibility()
    {
        // Create an author user
        $author = User::factory()->create(['account_type' => 'author']);

        // Create a book with public visibility
        $book = Book::factory()->create([
            'author_id' => $author->id,
            'visibility' => 'public',
        ]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Toggle visibility (should become private)
        $response = $this->patchJson("/api/books/{$book->id}/toggle-visibility");

        // Assert successful response
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'book',
                    'previous_visibility',
                    'current_visibility',
                ],
                'message'
            ]);

        // Assert visibility was toggled
        $responseData = $response->json('data');
        $this->assertEquals('public', $responseData['previous_visibility']);
        $this->assertEquals('private', $responseData['current_visibility']);

        // Assert in database
        $book->refresh();
        $this->assertEquals('private', $book->visibility);
    }

    /** @test */
    public function author_can_set_specific_visibility()
    {
        // Create an author user
        $author = User::factory()->create(['account_type' => 'author']);

        // Create a book with private visibility
        $book = Book::factory()->create([
            'author_id' => $author->id,
            'visibility' => 'private',
        ]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Set visibility to public explicitly
        $response = $this->patchJson("/api/books/{$book->id}/toggle-visibility", [
            'visibility' => 'public'
        ]);

        // Assert successful response
        $response->assertStatus(200);

        // Assert visibility was set to public
        $responseData = $response->json('data');
        $this->assertEquals('public', $responseData['current_visibility']);

        // Assert in database
        $book->refresh();
        $this->assertEquals('public', $book->visibility);
    }

    /** @test */
    public function admin_can_toggle_any_book_visibility()
    {
        // Create an admin user
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Create an author and their book
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create([
            'author_id' => $author->id,
            'visibility' => 'public',
        ]);

        // Authenticate as admin
        Sanctum::actingAs($admin);

        // Toggle visibility
        $response = $this->patchJson("/api/books/{$book->id}/toggle-visibility");

        // Assert successful response
        $response->assertStatus(200);

        // Assert visibility was toggled
        $book->refresh();
        $this->assertEquals('private', $book->visibility);
    }

    /** @test */
    public function user_cannot_toggle_others_book_visibility()
    {
        // Create two authors
        $author1 = User::factory()->create(['account_type' => 'author']);
        $author2 = User::factory()->create(['account_type' => 'author']);

        // Create a book for author1
        $book = Book::factory()->create([
            'author_id' => $author1->id,
            'visibility' => 'public',
        ]);

        // Authenticate as author2
        Sanctum::actingAs($author2);

        // Try to toggle author1's book visibility
        $response = $this->patchJson("/api/books/{$book->id}/toggle-visibility");

        // Assert unauthorized response
        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Unauthorized. You can only toggle visibility of your own books.'
            ]);
    }

    /** @test */
    public function visibility_toggle_validates_input()
    {
        // Create an author user
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create(['author_id' => $author->id]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Try to set invalid visibility
        $response = $this->patchJson("/api/books/{$book->id}/toggle-visibility", [
            'visibility' => 'invalid_value'
        ]);

        // Assert validation error
        $response->assertStatus(400)
            ->assertJsonStructure([
                'data' => [
                    'visibility'
                ]
            ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_update_books()
    {
        // Create a book
        $book = Book::factory()->create();

        // Try to update without authentication
        $response = $this->putJson("/api/books/{$book->id}", [
            'title' => 'Unauthorized Update'
        ]);

        // Assert unauthorized
        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_toggle_visibility()
    {
        // Create a book
        $book = Book::factory()->create();

        // Try to toggle visibility without authentication
        $response = $this->patchJson("/api/books/{$book->id}/toggle-visibility");

        // Assert unauthorized
        $response->assertStatus(401);
    }
}
