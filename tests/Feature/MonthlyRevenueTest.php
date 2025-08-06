<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonthlyRevenueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_access_monthly_revenue_data()
    {
        // Create an admin user
        $admin = User::factory()->create([
            'account_type' => 'admin',
        ]);
        $admin->assignRole('admin');

        // Create transactions for different months
        Transaction::factory()->create([
            'amount' => 100.00,
            'status' => 'succeeded',
            'type' => 'purchase',
            'created_at' => Carbon::create(2024, 1, 15), // January
        ]);

        Transaction::factory()->create([
            'amount' => 200.00,
            'status' => 'succeeded',
            'type' => 'purchase',
            'created_at' => Carbon::create(2024, 2, 15), // February
        ]);

        Transaction::factory()->create([
            'amount' => 50.00,
            'status' => 'succeeded',
            'type' => 'earning',
            'created_at' => Carbon::create(2024, 1, 20), // January
        ]);

        // Create a failed transaction (should not be included)
        Transaction::factory()->create([
            'amount' => 500.00,
            'status' => 'failed',
            'type' => 'purchase',
            'created_at' => Carbon::create(2024, 3, 15), // March
        ]);

        // Authenticate as admin
        Sanctum::actingAs($admin);

        // Make request to monthly revenue endpoint
        $response = $this->getJson('/api/analytics/monthly-revenue');

        // Assert successful response
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'January',
                    'February',
                    'March',
                    'April',
                    'May',
                    'June',
                    'July',
                    'August',
                    'September',
                    'October',
                    'November',
                    'December',
                ]
            ]);

        $data = $response->json('data');

        // Assert correct revenue calculations
        $this->assertEquals(150.00, $data['January']); // 100 + 50
        $this->assertEquals(200.00, $data['February']);
        $this->assertEquals(0, $data['March']); // Failed transaction should not count
        $this->assertEquals(0, $data['April']); // No transactions
    }

    #[Test]
    public function regular_user_can_access_their_monthly_revenue_data()
    {
        // Create a regular user
        $user = User::factory()->create([
            'account_type' => 'author',
        ]);

        // Create transactions for the user
        Transaction::factory()->create([
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'succeeded',
            'type' => 'earning',
            'created_at' => Carbon::create(2024, 1, 15),
        ]);

        // Create transaction for another user (should not be included)
        $otherUser = User::factory()->create();
        Transaction::factory()->create([
            'user_id' => $otherUser->id,
            'amount' => 500.00,
            'status' => 'succeeded',
            'type' => 'purchase',
            'created_at' => Carbon::create(2024, 1, 20),
        ]);

        // Authenticate as the user
        Sanctum::actingAs($user);

        // Make request to monthly revenue endpoint
        $response = $this->getJson('/api/analytics/monthly-revenue');

        // Assert successful response
        $response->assertStatus(200);

        $data = $response->json('data');

        // Assert only user's revenue is included
        $this->assertEquals(100.00, $data['January']);
        $this->assertEquals(0, $data['February']);
    }

    #[Test]
    public function monthly_revenue_returns_all_months_with_zero_for_empty_months()
    {
        // Create an admin user
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Create only one transaction
        Transaction::factory()->create([
            'amount' => 100.00,
            'status' => 'succeeded',
            'type' => 'purchase',
            'created_at' => Carbon::create(2024, 6, 15), // June
        ]);

        // Authenticate as admin
        Sanctum::actingAs($admin);

        // Make request to monthly revenue endpoint
        $response = $this->getJson('/api/analytics/monthly-revenue');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Assert all 12 months are present
        $expectedMonths = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        foreach ($expectedMonths as $month) {
            $this->assertArrayHasKey($month, $data);
        }

        // Assert only June has revenue
        $this->assertEquals(0, $data['January']);
        $this->assertEquals(0, $data['May']);
        $this->assertEquals(100.00, $data['June']);
        $this->assertEquals(0, $data['July']);
        $this->assertEquals(0, $data['December']);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_monthly_revenue()
    {
        // Make request without authentication
        $response = $this->getJson('/api/analytics/monthly-revenue');

        // Assert unauthorized
        $response->assertStatus(401);
    }

    /** @test */
    public function monthly_revenue_only_includes_current_year_transactions()
    {
        // Create an admin user
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Create transaction for current year
        Transaction::factory()->create([
            'amount' => 100.00,
            'status' => 'succeeded',
            'type' => 'purchase',
            'created_at' => Carbon::create(Carbon::now()->year, 1, 15),
        ]);

        // Create transaction for previous year (should not be included)
        Transaction::factory()->create([
            'amount' => 500.00,
            'status' => 'succeeded',
            'type' => 'purchase',
            'created_at' => Carbon::create(Carbon::now()->year - 1, 1, 15),
        ]);

        // Authenticate as admin
        Sanctum::actingAs($admin);

        // Make request to monthly revenue endpoint
        $response = $this->getJson('/api/analytics/monthly-revenue');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Assert only current year transaction is included
        $this->assertEquals(100.00, $data['January']);
    }
}
