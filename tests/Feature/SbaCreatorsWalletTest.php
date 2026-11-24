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

    /** A sale $daysAgo days ago (past the 7-day hold by default, so it can be withdrawn). */
    private function sale(User $author, float $amount, string $currency = 'NGN', int $daysAgo = 10): Transaction
    {
        $t = Transaction::create([
            'id' => (string) Str::uuid(), 'user_id' => $author->id, 'reference' => uniqid('crs_'), 'status' => 'succeeded',
            'currency' => $currency, 'amount' => $amount, 'payment_provider' => 'app', 'description' => 'SBA Creators sale: Playbook',
            'type' => 'payout', 'direction' => 'credit', 'purpose_type' => 'creator_sale', 'purpose_id' => 1,
        ]);
        $t->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $t;
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

    #[Test]
    public function new_earnings_wait_seven_days_before_they_can_be_withdrawn(): void
    {
        $author = $this->authorWithBank(0);
        $this->sale($author, 3000, daysAgo: 2);
        $this->sale($author, 1000, daysAgo: 9);
        $this->mock(\App\Services\Paystack\PaystackTransferService::class)->shouldNotReceive('initiateTransfer');

        $this->getJson('/api/author/wallet/balance')->assertOk()
            ->assertJsonPath('data.available.amount', 1000)
            ->assertJsonPath('data.pending.amount', 3000)
            ->assertJsonPath('data.lifetime_earned.amount', 4000)
            ->assertJsonPath('data.hold_days', 7);

        $this->postJson('/api/author/wallet/paystack-withdraw', ['amount' => 2500])->assertStatus(422)
            ->assertJsonFragment(['error' => 'You can withdraw ₦1,000.00 now. ₦3,000.00 from recent sales becomes available from '
                . now()->subDays(2)->addDays(7)->format('j M') . ' (earnings wait 7 days in case of refunds).']);

        $this->travel(6)->days();
        $this->assertEquals(4000, $this->available());
    }

    #[Test]
    public function money_already_sent_automatically_cannot_be_withdrawn_again(): void
    {
        $author = $this->authorWithBank(0);
        // A physical-order earning, and the automatic payout that already sent it to the author's bank.
        foreach ([['app', 'succeeded'], ['paystack', 'succeeded']] as [$provider, $status]) {
            $t = Transaction::create([
                'id' => (string) Str::uuid(), 'user_id' => $author->id, 'reference' => uniqid('ord_'), 'status' => $status,
                'currency' => 'NGN', 'amount' => 7500, 'payment_provider' => $provider, 'description' => 'Order',
                'type' => 'payout', 'direction' => 'credit', 'purpose_type' => 'order', 'purpose_id' => 9,
            ]);
            $t->forceFill(['created_at' => now()->subDays(10)])->save();
        }

        $this->getJson('/api/author/wallet/balance')->assertOk()
            ->assertJsonPath('data.available.amount', 0)
            ->assertJsonPath('data.lifetime_earned.amount', 7500);
    }

    #[Test]
    public function changing_the_bank_account_pauses_withdrawals_and_tells_the_author(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $author = $this->authorWithBank(5000);
        $author->forceFill(['bank_account_number' => '0123456789', 'bank_code' => '058', 'first_name' => 'Solomon', 'last_name' => 'Abu', 'kyc_status' => 'verified'])->save();
        $paystack = $this->mock(\App\Services\Paystack\PaystackTransferService::class);
        $paystack->shouldReceive('resolveAccount')->andReturn('ABU SOLOMON JOHN');
        $paystack->shouldReceive('listBanks')->andReturn([['name' => 'OPay', 'code' => '999992']]);
        $paystack->shouldReceive('createRecipient')->andReturn('RCP_new');
        $paystack->shouldNotReceive('initiateTransfer');

        // The name the app sends is ignored: the bank's name is saved.
        $this->postJson('/api/author/wallet/ngn-account', ['account_name' => 'Someone Else', 'account_number' => '8012345678', 'bank_code' => '999992'])
            ->assertOk()->assertJsonPath('data.account_name', 'ABU SOLOMON JOHN');
        $this->assertSame('ABU SOLOMON JOHN', $author->fresh()->bank_account_name);
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\Generic\GenericAppNotification::class);

        $this->getJson('/api/author/wallet/balance')->assertJsonPath('data.can_withdraw', false);
        $this->postJson('/api/author/wallet/paystack-withdraw', ['amount' => 1000])->assertStatus(423);

        $this->travel(49)->hours();
        $paystack->shouldReceive('initiateTransfer')->andReturn(['status' => 'pending']);
        $this->getJson('/api/author/wallet/balance')->assertJsonPath('data.can_withdraw', true);
    }

    #[Test]
    public function payouts_only_go_to_an_account_in_the_authors_verified_name(): void
    {
        $author = $this->authorWithBank(0);
        $author->forceFill(['first_name' => 'Solomon', 'last_name' => 'Abu', 'kyc_status' => 'verified'])->save();
        $paystack = $this->mock(\App\Services\Paystack\PaystackTransferService::class);
        $paystack->shouldReceive('resolveAccount')->andReturn('CHIOMA OKAFOR');
        $paystack->shouldNotReceive('createRecipient');

        $this->postJson('/api/author/wallet/resolve-account', ['account_number' => '8012345678', 'bank_code' => '999992'])->assertStatus(422);
        $this->postJson('/api/author/wallet/ngn-account', ['account_name' => 'Solomon Abu', 'account_number' => '8012345678', 'bank_code' => '999992'])->assertStatus(422);
        $this->assertSame('RCP_test', $author->fresh()->paystack_recipient_code);
    }

    #[Test]
    public function the_team_is_alerted_when_paystack_holds_less_than_authors_are_owed(): void
    {
        $author = $this->authorWithBank(0);
        $this->sale($author, 50000);
        $this->sale($author, 20000, daysAgo: 1);
        $paystack = $this->mock(\App\Services\Paystack\PaystackTransferService::class);
        $paystack->shouldReceive('nairaBalance')->andReturn(60000.0);
        config(['creators.alert_email' => 'alerts@example.com']);
        \Illuminate\Support\Facades\Http::fake();
        \Illuminate\Support\Facades\Mail::fake();

        $this->artisan('wallet:check-balance')->expectsOutputToContain('₦70,000.00')->assertSuccessful();

        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\Generic\GenericAppNotification::class, fn ($m) => $m->hasTo('alerts@example.com'));
    }

    #[Test]
    public function every_job_is_on_the_schedule(): void
    {
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        foreach (['creators:charge-due', 'creators:membership-reminders', 'creators:plan-reminders', 'books:release-preorders',
            'subscriptions:expire', 'books:sync-analytics', 'wallet:check-balance', 'library:heal'] as $job) {
            $this->assertStringContainsString($job, $scheduled, "{$job} is not scheduled");
        }
    }
}
