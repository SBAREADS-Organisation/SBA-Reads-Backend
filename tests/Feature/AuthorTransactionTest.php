<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorTransactionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function author_can_access_their_transaction_history()
    {
        // Create an author user
        $author = User::factory()->create([
            'account_type' => 'author',
        ]);

        // Create some transactions for the author
        $transactions = Transaction::factory()->count(3)->create([
            'user_id' => $author->id,
            'type' => 'payout',
            'direction' => 'credit',
            'status' => 'succeeded',
        ]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Make request to the author transactions endpoint
        $response = $this->getJson('/api/author/transactions');

        // Assert successful response
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'user_id',
                            'reference',
                            'status',
                            'currency',
                            'type',
                            'amount',
                            'description',
                            'direction',
                            'created_at',
                            'updated_at',
                        ]
                    ],
                    'current_page',
                    'per_page',
                    'total',
                ]
            ]);

        // Assert that the response contains the author's transactions
        $responseData = $response->json('data.data');
        $this->assertCount(3, $responseData);

        // Verify all transactions belong to the author
        foreach ($responseData as $transaction) {
            $this->assertEquals($author->id, $transaction['user_id']);
        }
    }

    /** @test */
    public function non_author_cannot_access_author_transactions()
    {
        // Create a reader user
        $reader = User::factory()->create([
            'account_type' => 'reader',
        ]);

        // Authenticate as the reader
        Sanctum::actingAs($reader);

        // Make request to the author transactions endpoint
        $response = $this->getJson('/api/author/transactions');

        // Assert access denied
        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Access denied. Only authors can access this endpoint.',
            ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_author_transactions()
    {
        // Make request without authentication
        $response = $this->getJson('/api/author/transactions');

        // Assert unauthorized
        $response->assertStatus(401);
    }

    /** @test */
    public function author_can_filter_transactions_by_status()
    {
        // Create an author user
        $author = User::factory()->create([
            'account_type' => 'author',
        ]);

        // Create transactions with different statuses
        Transaction::factory()->create([
            'user_id' => $author->id,
            'status' => 'succeeded',
        ]);

        Transaction::factory()->create([
            'user_id' => $author->id,
            'status' => 'pending',
        ]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Filter by succeeded status
        $response = $this->getJson('/api/author/transactions?status=succeeded');

        $response->assertStatus(200);
        $responseData = $response->json('data.data');

        // Assert only succeeded transactions are returned
        foreach ($responseData as $transaction) {
            $this->assertEquals('succeeded', $transaction['status']);
        }
    }

    /** @test */
    public function author_can_filter_transactions_by_type()
    {
        // Create an author user
        $author = User::factory()->create([
            'account_type' => 'author',
        ]);

        // Create transactions with different types
        Transaction::factory()->create([
            'user_id' => $author->id,
            'type' => 'payout',
        ]);

        Transaction::factory()->create([
            'user_id' => $author->id,
            'type' => 'purchase',
        ]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Filter by payout type
        $response = $this->getJson('/api/author/transactions?type=payout');

        $response->assertStatus(200);
        $responseData = $response->json('data.data');

        // Assert only payout transactions are returned
        foreach ($responseData as $transaction) {
            $this->assertEquals('payout', $transaction['type']);
        }
    }

    /** @test */
    public function author_transactions_are_paginated()
    {
        // Create an author user
        $author = User::factory()->create([
            'account_type' => 'author',
        ]);

        // Create more transactions than the default per_page
        Transaction::factory()->count(20)->create([
            'user_id' => $author->id,
        ]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Make request with pagination
        $response = $this->getJson('/api/author/transactions?per_page=5');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'data',
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ]
            ]);

        // Assert pagination works
        $this->assertEquals(5, count($response->json('data.data')));
        $this->assertEquals(20, $response->json('data.total'));
        $this->assertEquals(4, $response->json('data.last_page'));
    }

    /** @test */
    public function author_gets_404_when_no_transactions_exist()
    {
        // Create an author user with no transactions
        $author = User::factory()->create([
            'account_type' => 'author',
        ]);

        // Authenticate as the author
        Sanctum::actingAs($author);

        // Make request to the author transactions endpoint
        $response = $this->getJson('/api/author/transactions');

        // Assert 404 response
        $response->assertStatus(404)
            ->assertJson([
                'message' => 'No transactions found for this author',
            ]);
    }
}
