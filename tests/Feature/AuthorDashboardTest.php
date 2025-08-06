<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create roles
        Role::create(['name' => 'author', 'guard_name' => 'api']);
        Role::create(['name' => 'reader', 'guard_name' => 'api']);
    }

    public function test_author_can_access_dashboard(): void
    {
        // Create an author user
        $author = User::factory()->create([
            'account_type' => 'author',
            'email' => 'author@test.com'
        ]);
        $author->assignRole('author');

        // Authenticate the author
        Sanctum::actingAs($author);

        // Make request to author dashboard
        $response = $this->getJson('/api/author/dashboard');

        // Assert successful response
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'revenue',
                         'reader_engagement' => [
                             'active_readers',
                             'total_reading_sessions',
                             'average_reading_progress',
                             'total_reading_time_minutes'
                         ],
                         'books_published',
                         'total_sales',
                         'total_books_count',
                         'pending_books_count',
                         'recent_transactions',
                         'recent_book_uploads'
                     ],
                     'code',
                     'message'
                 ]);
    }

    public function test_non_author_cannot_access_author_dashboard(): void
    {
        // Create a reader user
        $reader = User::factory()->create([
            'account_type' => 'reader',
            'email' => 'reader@test.com'
        ]);
        $reader->assignRole('reader');

        // Authenticate the reader
        Sanctum::actingAs($reader);

        // Make request to author dashboard
        $response = $this->getJson('/api/author/dashboard');

        // Assert forbidden response
        $response->assertStatus(403)
                 ->assertJson([
                     'code' => 403,
                     'error' => 'Access denied. Only authors can access this dashboard.'
                 ]);
    }

    public function test_unauthenticated_user_cannot_access_author_dashboard(): void
    {
        // Make request without authentication
        $response = $this->getJson('/api/author/dashboard');

        // Assert unauthorized response
        $response->assertStatus(401);
    }
}
