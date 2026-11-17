<?php

namespace Tests\Feature;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorAffiliate;
use App\Models\CreatorDiscount;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSubscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Paystack\PaystackWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Discount codes, affiliates and email lists on SBA Creators. */
class SbaCreatorsMarketingTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $buyer;

    /** @var array<int, array<string, mixed>> */
    private array $payments = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();
        $this->creator = User::factory()->create(['account_type' => 'author', 'kyc_status' => 'verified', 'email' => 'creator@example.com']);
        $this->buyer = User::factory()->create(['account_type' => 'reader', 'email' => 'buyer@example.com']);

        $this->mock(PaymentService::class)->shouldReceive('createPayment')->andReturnUsing(function (array $data, User $user) {
            $this->payments[] = $data;

            return Transaction::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'reference' => uniqid('test_'),
                'amount' => $data['amount'], 'currency' => $data['currency'], 'payment_provider' => 'paystack',
                'purpose_type' => $data['purpose'], 'purpose_id' => $data['purpose_id'], 'meta_data' => $data['meta_data'] ?? [],
                'status' => 'pending', 'type' => 'purchase', 'direction' => 'debit',
                'payment_client_secret' => 'https://checkout.paystack.com/' . uniqid(),
            ]);
        });
    }

    /** A published ₦10,000 course; the store pays the fee itself, on the free Pro month (2.5%). */
    private function course(): CreatorProduct
    {
        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'])->assertCreated();
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'course', 'title' => 'Write Your Book', 'price' => 10000])->json('data.product.id');
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'One', 'kind' => 'text', 'body' => 'x'])->assertCreated();
        $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])->assertOk();

        return CreatorProduct::find($id);
    }

    private function confirmAll(): void
    {
        $service = app(PaystackWebhookService::class);
        $process = new \ReflectionMethod($service, 'processSuccessfulTransaction');
        $fulfilled = new \ReflectionMethod($service, 'isPurchaseFulfilled');
        foreach (Transaction::where('purpose_type', 'creator_purchase')->get() as $transaction) {
            if (! $fulfilled->invoke($service, $transaction->fresh())) {
                $process->invoke($service, $transaction->fresh(), []);
            }
        }
    }

    #[Test]
    public function a_percentage_code_lowers_the_price_and_counts_its_uses(): void
    {
        $product = $this->course();
        $this->postJson('/api/creators/studio/discounts', ['code' => 'launch20', 'kind' => 'percent', 'value' => 20, 'max_uses' => 1])
            ->assertCreated()->assertJsonPath('data.discount.code', 'LAUNCH20');

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/creators/products/{$product->id}/quote?code=launch20")
            ->assertOk()->assertJsonPath('data.discount', 2000)->assertJsonPath('data.amount_to_pay', 8000);
        $this->getJson("/api/creators/products/{$product->id}/quote?code=NOPE")->assertStatus(422)->assertJsonPath('error', 'That discount code is not valid.');

        $this->postJson("/api/creators/products/{$product->id}/buy", ['code' => 'LAUNCH20'])->assertOk()->assertJsonPath('data.amount', 8000);
        $this->confirmAll();

        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);
        $this->assertEquals(2000, $purchase->discount);
        $this->assertEquals(7800, $purchase->creator_earning); // 8,000 less the 2.5% fee
        $this->assertSame(1, CreatorDiscount::first()->uses);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/creators/products/{$product->id}/quote?code=LAUNCH20")->assertStatus(422)->assertJsonPath('error', 'This code has been used up.');
    }

    #[Test]
    public function a_full_discount_makes_it_free_without_payment(): void
    {
        $product = $this->course();
        $this->postJson('/api/creators/studio/discounts', ['code' => 'GIFT', 'kind' => 'percent', 'value' => 100, 'product_id' => $product->id])->assertCreated();

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy", ['code' => 'gift'])->assertCreated();
        $this->assertTrue($product->purchasedBy($this->buyer));
        $this->assertSame([], $this->payments);
    }

    #[Test]
    public function codes_check_expiry_product_and_switching_off(): void
    {
        $product = $this->course();
        $other = CreatorProduct::create(['store_id' => $product->store_id, 'user_id' => $this->creator->id, 'type' => 'course', 'title' => 'Other', 'slug' => 'other', 'price' => 5000, 'currency' => 'NGN', 'status' => 'published']);
        $this->postJson('/api/creators/studio/discounts', ['code' => 'ONLYOTHER', 'kind' => 'amount', 'value' => 500, 'product_id' => $other->id])->assertCreated();
        $id = $this->postJson('/api/creators/studio/discounts', ['code' => 'OFF', 'kind' => 'amount', 'value' => 500])->json('data.discount.id');
        $this->postJson("/api/creators/studio/discounts/{$id}", ['active' => false])->assertOk();
        $this->postJson('/api/creators/studio/discounts', ['code' => 'BIG', 'kind' => 'percent', 'value' => 150])->assertStatus(422);
        CreatorDiscount::create(['store_id' => $product->store_id, 'code' => 'OLD', 'kind' => 'amount', 'value' => 500, 'expires_at' => now()->subDay()]);

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/creators/products/{$product->id}/quote?code=ONLYOTHER")->assertJsonPath('error', 'This code does not apply to this product.');
        $this->getJson("/api/creators/products/{$product->id}/quote?code=OFF")->assertJsonPath('error', 'This code is no longer active.');
        $this->getJson("/api/creators/products/{$product->id}/quote?code=OLD")->assertJsonPath('error', 'This code has expired.');
    }

    #[Test]
    public function an_affiliate_earns_their_share_from_the_creator(): void
    {
        $product = $this->course();
        $this->postJson('/api/creators/studio/affiliates/settings', ['rate' => 0.2])->assertOk();

        $affiliateUser = User::factory()->create(['email' => 'promoter@example.com']);
        Sanctum::actingAs($affiliateUser);
        $code = $this->postJson('/api/creators/stores/grace-academy/affiliate')->assertCreated()->json('data.affiliate.code');
        $this->getJson('/api/creators/affiliate')->assertJsonPath('data.affiliates.0.products.0.link', fn ($link) => str_ends_with($link, "/creators/grace-academy/write-your-book?ref={$code}"));

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy", ['ref' => strtolower($code)])->assertOk();
        $this->confirmAll();

        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);
        $this->assertEquals(1950, $purchase->affiliate_earning); // 20% of the 9,750 the creator would keep
        $this->assertEquals(7800, $purchase->creator_earning);
        $this->assertEquals(1950, Transaction::where('purpose_type', 'creator_affiliate')->where('user_id', $affiliateUser->id)->sum('amount'));
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('promoter@example.com') && str_contains($m->title, 'affiliate commission'));

        Sanctum::actingAs($this->creator);
        $this->getJson('/api/creators/studio/affiliates')->assertJsonPath('data.affiliates.0.sales', 1)->assertJsonPath('data.affiliates.0.earned', 1950);
    }

    #[Test]
    public function affiliates_cannot_earn_on_their_own_purchase_or_when_switched_off(): void
    {
        $product = $this->course();
        $this->postJson('/api/creators/studio/affiliates/settings', ['rate' => 0.2])->assertOk();
        Sanctum::actingAs($this->buyer);
        $code = $this->postJson('/api/creators/stores/grace-academy/affiliate')->json('data.affiliate.code');
        $this->postJson("/api/creators/products/{$product->id}/buy", ['ref' => $code])->assertOk();
        $this->confirmAll();
        $this->assertEquals(0, CreatorPurchase::first()->affiliate_earning);

        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/affiliates/settings', ['rate' => null])->assertOk();
        $this->postJson('/api/creators/stores/grace-academy/affiliate')->assertStatus(422);
        $this->assertSame(1, CreatorAffiliate::count());
    }

    #[Test]
    public function buyers_and_signups_join_the_list_and_can_unsubscribe(): void
    {
        $product = $this->course();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk();
        $this->confirmAll();
        $this->postJson('/api/creators/stores/grace-academy/subscribe', ['email' => 'Fan@Example.com', 'name' => 'Fan'])->assertOk();
        $this->postJson('/api/creators/stores/grace-academy/subscribe', ['email' => 'fan@example.com'])->assertOk();

        $this->assertSame(2, CreatorSubscriber::count());
        $token = CreatorSubscriber::firstWhere('email', 'fan@example.com')->token;
        $this->postJson("/api/creators/unsubscribe/{$token}")->assertOk()->assertJsonPath('data.store', 'Grace Academy');

        Sanctum::actingAs($this->creator);
        $this->getJson('/api/creators/studio/subscribers')->assertJsonPath('data.active', 1)->assertJsonPath('data.unsubscribed', 1);
        $csv = $this->get('/api/creators/studio/subscribers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('fan@example.com,Fan,signup,no', $csv);
    }

    #[Test]
    public function a_broadcast_reaches_subscribed_people_once_a_day(): void
    {
        $product = $this->course();
        CreatorSubscriber::add($product->store_id, 'one@example.com', null, 'signup');
        CreatorSubscriber::add($product->store_id, 'gone@example.com', null, 'signup')->update(['unsubscribed_at' => now()]);

        $this->postJson('/api/creators/studio/broadcasts', ['subject' => 'New course out', 'body' => 'Come and see.'])
            ->assertOk()->assertJsonPath('data.recipients', 1);
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('one@example.com') && str_contains($m->body, '/creators/unsubscribe/'));
        Mail::assertNotQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('gone@example.com'));

        $this->postJson('/api/creators/studio/broadcasts', ['subject' => 'Again', 'body' => 'Hi'])->assertStatus(429);
    }
}
