<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** SBA Creators earnings show in the author's wallet, in naira. */
class SbaCreatorsWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The wallet's Paystack and Stripe helpers need keys to be built (production has real ones).
        config(['services.paystack.secret' => 'sk_test_dummy', 'services.paystack.base_url' => 'https://api.paystack.co', 'services.stripe.secret' => 'sk_test_dummy']);
    }

    private function sale(User $author, float $amount, string $currency = 'NGN'): void
    {
        Transaction::create([
            'id' => (string) Str::uuid(), 'user_id' => $author->id, 'reference' => uniqid('crs_'), 'status' => 'succeeded',
            'currency' => $currency, 'amount' => $amount, 'payment_provider' => 'app', 'description' => 'SBA Creators sale: Playbook',
            'type' => 'payout', 'direction' => 'credit', 'purpose_type' => 'creator_sale', 'purpose_id' => 1,
        ]);
    }

    #[Test]
    public function creator_earnings_show_in_naira_before_a_bank_is_added(): void
    {
        Role::findOrCreate('author', 'api');
        $author = User::factory()->create(['account_type' => 'author']);
        $author->assignRole(Role::findByName('author', 'api'));
        $this->sale($author, 500);
        $this->sale($author, 1500);

        Sanctum::actingAs($author);
        $this->getJson('/api/author/wallet/balance')->assertOk()
            ->assertJsonPath('data.available.amount', 2000)
            ->assertJsonPath('data.available.currency', 'NGN')
            ->assertJsonPath('data.lifetime_earned.currency', 'NGN')
            ->assertJsonPath('data.can_withdraw', false);
    }

    #[Test]
    public function dollar_only_earnings_still_show_in_dollars(): void
    {
        Role::findOrCreate('author', 'api');
        $author = User::factory()->create(['account_type' => 'author']);
        $author->assignRole(Role::findByName('author', 'api'));
        $this->sale($author, 12.5, 'USD');

        Sanctum::actingAs($author);
        $this->getJson('/api/author/wallet/balance')->assertOk()
            ->assertJsonPath('data.available.amount', 12.5)
            ->assertJsonPath('data.available.currency', 'USD');
    }

    #[Test]
    public function creator_earnings_are_withdrawable_once_a_bank_is_added(): void
    {
        Role::findOrCreate('author', 'api');
        $author = User::factory()->create(['account_type' => 'author', 'paystack_recipient_code' => 'RCP_test']);
        $author->assignRole(Role::findByName('author', 'api'));
        $this->sale($author, 500);

        Sanctum::actingAs($author);
        $this->getJson('/api/author/wallet/balance')->assertOk()
            ->assertJsonPath('data.payout_method', 'paystack')
            ->assertJsonPath('data.available.amount', 500)
            ->assertJsonPath('data.available.currency', 'NGN')
            ->assertJsonPath('data.can_withdraw', true);
    }
}
