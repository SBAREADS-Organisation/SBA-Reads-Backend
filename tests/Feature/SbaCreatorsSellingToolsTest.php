<?php

namespace Tests\Feature;

use App\Mail\Generic\GenericAppNotification;
use App\Models\Book;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSavedCard;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Paystack\PaystackService;
use App\Services\Paystack\PaystackWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Instalments, automatic membership renewal, checkout add-ons and bonus ebooks on SBA Creators. */
class SbaCreatorsSellingToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $buyer;

    /** @var array<int, array<string, mixed>> */
    private array $payments = [];

    /** @var array<int, array<string, mixed>> */
    private array $charges = [];

    private bool $cardWorks = true;

    /** What Paystack sends for a successful card payment. */
    private const CARD = [
        'authorization' => [
            'authorization_code' => 'AUTH_test123', 'reusable' => true, 'channel' => 'card',
            'last4' => '4081', 'card_type' => 'visa', 'exp_month' => '12', 'exp_year' => '2030', 'signature' => 'SIG_abc',
        ],
        'customer' => ['email' => 'buyer@example.com'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Saved cards are encrypted, which needs an app key (production has one; the test setup may not).
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
            $this->app->forgetInstance('encrypter');
        }
        foreach (['admin', 'superadmin', 'manager', 'author', 'user', 'reader'] as $role) {
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
        $this->mock(PaystackService::class)->shouldReceive('chargeAuthorization')->andReturnUsing(function ($email, $amount, $code, $reference) {
            $this->charges[] = compact('email', 'amount', 'code', 'reference');

            return $this->cardWorks
                ? ['status' => true, 'data' => ['status' => 'success', 'reference' => $reference] + self::CARD]
                : ['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient Funds']];
        });
    }

    private function openStore(): void
    {
        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'])->assertCreated();
    }

    private function product(string $type, float $price, array $settings = [], string $title = 'Write Your Book'): CreatorProduct
    {
        Sanctum::actingAs($this->creator);
        $id = $this->postJson('/api/creators/studio/products', ['type' => $type, 'title' => $title, 'price' => $price])->json('data.product.id');
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'One', 'kind' => 'text', 'body' => 'Secret words'])->assertCreated();
        $this->postJson("/api/creators/studio/products/{$id}", $settings + ['status' => 'published'])->assertOk();

        return CreatorProduct::find($id);
    }

    /** Runs what a confirmed Paystack payment runs, with the card details Paystack sends. */
    private function confirmAll(array $card = self::CARD): void
    {
        $service = app(PaystackWebhookService::class);
        $process = new \ReflectionMethod($service, 'processSuccessfulTransaction');
        $fulfilled = new \ReflectionMethod($service, 'isPurchaseFulfilled');
        foreach (Transaction::whereIn('purpose_type', ['creator_purchase', 'creator_instalment'])->where('payment_provider', 'paystack')->get() as $transaction) {
            if (! $fulfilled->invoke($service, $transaction->fresh())) {
                $process->invoke($service, $transaction->fresh(), $card);
            }
        }
    }

    private function earned(User $user): float
    {
        return (float) Transaction::where('user_id', $user->id)->where('purpose_type', 'creator_sale')->sum('amount');
    }

    #[Test]
    public function a_course_can_be_paid_in_instalments_charged_to_the_saved_card(): void
    {
        $this->openStore();
        $course = $this->product('course', 12000, ['instalments_max' => 3]);

        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/creators/stores/grace-academy/products/' . $course->slug)->assertJsonPath('data.product.instalments_max', 3);
        $this->postJson("/api/creators/products/{$course->id}/buy", ['instalments' => 3])->assertOk()->assertJsonPath('data.amount', 4000);
        $this->confirmAll();

        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);
        $this->assertSame(1, $purchase->instalments_paid);
        $this->assertSame('active', $purchase->instalment_status);
        $this->assertNotNull($purchase->card_id);
        $this->assertSame('AUTH_test123', CreatorSavedCard::first()->authorization_code);
        $this->assertNotSame('AUTH_test123', DB::table('creator_saved_cards')->value('authorization_code')); // stored encrypted
        $this->assertTrue($course->purchasedBy($this->buyer));
        $this->assertEquals(3900, $this->earned($this->creator)); // a third of 11,700 (12,000 less 2.5%)

        foreach ([2, 3] as $n) {
            $purchase->fresh()->update(['next_instalment_at' => now()->subMinute()]);
            $this->artisan('creators:charge-due')->assertSuccessful();
            $this->assertSame($n, $purchase->fresh()->instalments_paid);
        }
        $this->assertSame('completed', $purchase->fresh()->instalment_status);
        $this->assertEquals(11700, $this->earned($this->creator));
        $this->assertEquals([4000, 4000], array_column($this->charges, 'amount'));

        $this->artisan('creators:charge-due')->assertSuccessful();
        $this->assertCount(2, $this->charges); // nothing more to collect
    }

    #[Test]
    public function missed_instalments_lock_the_course_until_paid(): void
    {
        $this->openStore();
        $course = $this->product('course', 9000, ['instalments_max' => 2]);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$course->id}/buy", ['instalments' => 2])->assertOk();
        $this->confirmAll();
        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);

        $this->cardWorks = false;
        foreach (range(1, 3) as $attempt) {
            $purchase->fresh()->update(['next_instalment_at' => now()->subMinute()]);
            $this->artisan('creators:charge-due')->assertSuccessful();
        }
        $this->assertSame('defaulted', $purchase->fresh()->instalment_status);
        $this->assertFalse($course->purchasedBy($this->buyer));
        $this->getJson("/api/creators/products/{$course->id}/content")->assertJsonPath('data.has_access', false)
            ->assertJsonPath('data.instalments.status', 'defaulted')->assertJsonPath('data.instalments.next_amount', 4500);
        $this->postJson("/api/creators/products/{$course->id}/buy")->assertStatus(409);
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => str_contains($m->title, 'is locked'));

        // Paying the missed instalment by link brings access back.
        $this->postJson("/api/creators/purchases/{$purchase->id}/pay-instalment")->assertOk()->assertJsonPath('data.amount', 4500);
        $this->confirmAll();
        $this->assertSame('completed', $purchase->fresh()->instalment_status);
        $this->assertTrue($course->purchasedBy($this->buyer));
    }

    #[Test]
    public function instalments_follow_the_creators_rules(): void
    {
        $this->openStore();
        $this->postJson('/api/creators/studio/products', ['type' => 'download', 'title' => 'Workbook', 'price' => 9000]);
        $download = CreatorProduct::firstWhere('title', 'Workbook');
        $this->postJson("/api/creators/studio/products/{$download->id}", ['instalments_max' => 2])->assertStatus(422)->assertJsonPath('error', 'Only courses can be paid in instalments.');
        $cheap = $this->product('course', 3000, [], 'Cheap course');
        $this->postJson("/api/creators/studio/products/{$cheap->id}", ['instalments_max' => 2])->assertStatus(422);
        $course = $this->product('course', 9000, ['instalments_max' => 2], 'Big course');

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$course->id}/buy", ['instalments' => 3])->assertStatus(422);
        $this->postJson("/api/creators/products/{$cheap->id}/buy", ['instalments' => 2])->assertStatus(422);
    }

    #[Test]
    public function a_membership_renews_itself_with_the_saved_card(): void
    {
        $this->openStore();
        $membership = $this->product('membership', 3000, [], 'Writers Circle');
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$membership->id}/buy", ['auto_renew' => true])->assertOk();
        $this->confirmAll();
        $this->getJson("/api/creators/products/{$membership->id}/content")
            ->assertJsonPath('data.renewal.auto_renew', true)->assertJsonPath('data.renewal.card', 'Visa ending 4081');

        $first = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);
        $first->update(['access_until' => now()->addHours(12)]);
        $this->artisan('creators:charge-due')->assertSuccessful();
        $this->artisan('creators:charge-due')->assertSuccessful();

        $this->assertCount(1, $this->charges);
        $this->assertTrue($membership->memberUntil($this->buyer)->between(now()->addDays(30), now()->addDays(31)));
        $this->assertSame(2, CreatorPurchase::where('buyer_id', $this->buyer->id)->where('status', 'paid')->count());
        $this->assertTrue((bool) CreatorPurchase::where('buyer_id', $this->buyer->id)->latest('id')->first()->auto_renew);
    }

    #[Test]
    public function a_failed_renewal_tells_the_member_and_renewal_can_be_switched_off(): void
    {
        $this->openStore();
        $membership = $this->product('membership', 3000, [], 'Writers Circle');
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$membership->id}/buy", ['auto_renew' => true])->assertOk();
        $this->confirmAll();
        $first = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);
        $first->update(['access_until' => now()->addHours(12)]);

        $this->cardWorks = false;
        $this->artisan('creators:charge-due')->assertSuccessful();
        $this->assertNotNull($first->fresh()->renewal_failed_at);
        $this->assertSame(1, CreatorPurchase::where('buyer_id', $this->buyer->id)->count());
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('buyer@example.com') && str_contains($m->title, "couldn't renew"));

        $this->postJson("/api/creators/products/{$membership->id}/auto-renew", ['enabled' => false])->assertOk();
        $this->assertFalse($first->fresh()->auto_renew);
    }

    #[Test]
    public function a_checkout_add_on_is_bought_in_the_same_payment(): void
    {
        $this->openStore();
        $workbook = $this->product('download', 5000, [], 'Workbook');
        $course = $this->product('course', 10000, ['upsell_product_id' => $workbook->id, 'upsell_price' => 2000]);

        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/creators/stores/grace-academy/products/' . $course->slug)
            ->assertJsonPath('data.product.upsell.title', 'Workbook')->assertJsonPath('data.product.upsell.price', 2000);
        $this->postJson("/api/creators/products/{$course->id}/buy", ['upsell' => true])->assertOk()->assertJsonPath('data.amount', 12000);
        $this->confirmAll();

        $this->assertTrue($course->purchasedBy($this->buyer));
        $this->assertTrue($workbook->purchasedBy($this->buyer));
        $this->assertEquals(9750 + 1950, $this->earned($this->creator));

        // Already owned, the add-on isn't charged again.
        $other = $this->product('course', 4000, ['upsell_product_id' => $workbook->id, 'upsell_price' => 1500], 'Second course');
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$other->id}/buy", ['upsell' => true])->assertOk()->assertJsonPath('data.amount', 4000);
    }

    #[Test]
    public function a_bonus_ebook_goes_into_the_buyers_library(): void
    {
        $this->openStore();
        $book = Book::factory()->create(['author_id' => $this->creator->id, 'status' => 'approved', 'title' => 'My Book']);
        $strangers = Book::factory()->create(['status' => 'approved']);
        $this->getJson('/api/creators/studio/books')->assertJsonPath('data.books.0.title', 'My Book');
        $course = $this->product('course', 0, ['bonus_book_id' => $book->id]);
        $this->postJson("/api/creators/studio/products/{$course->id}", ['bonus_book_id' => $strangers->id])->assertStatus(422);

        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/creators/stores/grace-academy/products/' . $course->slug)->assertJsonPath('data.product.bonus_book.title', 'My Book');
        $this->postJson("/api/creators/products/{$course->id}/buy")->assertCreated();
        $this->assertTrue(DB::table('book_user')->where('user_id', $this->buyer->id)->where('book_id', $book->id)->exists());
    }

    #[Test]
    public function add_ons_must_come_from_the_same_store_at_a_fair_price(): void
    {
        $this->openStore();
        $course = $this->product('course', 10000);
        $workbook = $this->product('download', 5000, [], 'Workbook');
        $this->postJson("/api/creators/studio/products/{$course->id}", ['upsell_product_id' => $workbook->id, 'upsell_price' => 6000])->assertStatus(422);
        $this->postJson("/api/creators/studio/products/{$course->id}", ['upsell_product_id' => $course->id, 'upsell_price' => 1000])->assertStatus(422);

        $rival = User::factory()->create(['account_type' => 'author']);
        $rivalStore = \App\Models\CreatorStore::create(['user_id' => $rival->id, 'name' => 'Rival', 'slug' => 'rival', 'fee_payer' => 'creator', 'plan' => 'starter']);
        $someoneElse = CreatorProduct::create(['store_id' => $rivalStore->id, 'user_id' => $rival->id, 'type' => 'course', 'title' => 'X', 'slug' => 'x', 'price' => 5000, 'currency' => 'NGN', 'status' => 'published']);
        $this->postJson("/api/creators/studio/products/{$course->id}", ['upsell_product_id' => $someoneElse->id, 'upsell_price' => 1000])->assertStatus(422);
    }
}
