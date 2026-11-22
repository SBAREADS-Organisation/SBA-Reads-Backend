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

    private function authorWithBank(float $earned): User
    {
        Role::findOrCreate('author', 'api');
        $author = User::factory()->create(['account_type' => 'author', 'paystack_recipient_code' => 'RCP_test', 'payout_method' => 'paystack', 'bank_name' => 'OPay']);
        $author->assignRole(Role::findByName('author', 'api'));
        $this->sale($author, $earned);
        Sanctum::actingAs($author);

        return $author;
    }

    private function available(): float
    {
        return (float) $this->getJson('/api/author/wallet/balance')->json('data.available.amount');
    }

    #[Test]
    public function a_withdrawal_is_recorded_before_money_moves_and_lowers_the_balance(): void
    {
        $this->authorWithBank(6572.45);
        $sentReference = null;
        $this->mock(\App\Services\Paystack\PaystackTransferService::class)->shouldReceive('initiateTransfer')
            ->once()->andReturnUsing(function ($kobo, $recipient, $reason, $reference) use (&$sentReference) {
                // The payout is already on record when Paystack is asked to send it.
                $this->assertDatabaseHas('transactions', ['reference' => $reference, 'status' => 'pending', 'purpose_type' => 'paystack_payout']);
                $this->assertSame(328600, $kobo);
                $sentReference = $reference;

                return ['reference' => $reference, 'status' => 'pending', 'transfer_code' => 'TRF_1'];
            });

        $this->postJson('/api/author/wallet/paystack-withdraw', ['amount' => 3286])->assertOk();

        $this->assertNotNull($sentReference);
        $this->assertEquals(3286.45, $this->available());
        $this->assertDatabaseHas('transactions', ['reference' => $sentReference, 'description' => 'Withdrawal to OPay']);
    }

    #[Test]
    public function a_withdrawal_paystack_refuses_gives_the_money_back(): void
    {
        $this->authorWithBank(5000);
        $this->mock(\App\Services\Paystack\PaystackTransferService::class)->shouldReceive('initiateTransfer')
            ->andThrow(new \RuntimeException('Paystack transfer failed: Invalid recipient'));

        $this->postJson('/api/author/wallet/paystack-withdraw', ['amount' => 2000])->assertStatus(422);

        $this->assertEquals(5000, $this->available());
        $this->assertDatabaseHas('transactions', ['purpose_type' => 'paystack_payout', 'status' => 'failed']);
    }

    #[Test]
    public function a_withdrawal_with_no_answer_from_paystack_stays_pending_so_it_cannot_be_paid_twice(): void
    {
        $this->authorWithBank(5000);
        $this->mock(\App\Services\Paystack\PaystackTransferService::class)->shouldReceive('initiateTransfer')
            ->andThrow(new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->postJson('/api/author/wallet/paystack-withdraw', ['amount' => 5000])->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertEquals(0, $this->available());
        $this->postJson('/api/author/wallet/paystack-withdraw', ['amount' => 5000])->assertStatus(422);
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
