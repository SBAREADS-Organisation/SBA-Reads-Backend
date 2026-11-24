<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Paystack\CurrencyConversionService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeWebhookService;
use App\Support\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Tests\TestCase;

/** Buyers abroad pay on the website by international card (Stripe), for books and SBA Creators products. */
class InternationalCardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();
        config(['services.web.url' => 'https://sbareads.com']);

        // ₦1,500 to the dollar.
        $fx = Mockery::mock(CurrencyConversionService::class);
        $fx->shouldReceive('convert')->andReturnUsing(fn ($amount, $from, $to) => $from === $to ? (float) $amount
            : ($from === 'NGN' && $to === 'USD' ? $amount / 1500 : $amount * 1500));
        $this->app->instance(CurrencyConversionService::class, $fx);

        $stripe = Mockery::mock(StripeConnectService::class);
        $stripe->shouldReceive('createCheckoutSession')->andReturnUsing(function (array $payload) {
            $this->sessions[] = $payload;

            return Session::constructFrom(['id' => 'cs_test_' . count($this->sessions), 'url' => 'https://checkout.stripe.com/c/pay/cs_test_' . count($this->sessions)]);
        });
        $this->app->instance(StripeConnectService::class, $stripe);
    }

    /** A published ₦15,000 course from a verified creator. */
    private function course(): CreatorProduct
    {
        $creator = User::factory()->create(['account_type' => 'author', 'kyc_status' => 'verified']);
        Sanctum::actingAs($creator);
        $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'])->assertCreated();
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'course', 'title' => 'Introduction to Marriage', 'price' => 15000, 'instalments_max' => 3])->json('data.product.id');
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'One', 'kind' => 'text', 'body' => 'x'])->assertCreated();
        $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])->assertOk();

        return CreatorProduct::find($id);
    }

    private function cardPaid(Transaction $transaction, int $cents, string $currency = 'usd'): void
    {
        app(StripeWebhookService::class)->handlePaymentIntentSucceeded(PaymentIntent::constructFrom([
            'id' => 'pi_test', 'amount' => $cents, 'amount_received' => $cents, 'currency' => $currency,
            'metadata' => ['reference' => $transaction->reference],
        ]));
    }

    private function creatorWallet(CreatorProduct $product): float
    {
        return (float) Transaction::where('user_id', $product->store->user_id)->where('purpose_type', 'creator_sale')
            ->where('status', 'succeeded')->where('currency', 'NGN')->sum('amount');
    }

    #[Test]
    public function a_buyer_abroad_pays_in_dollars_and_the_creator_is_credited_in_naira(): void
    {
        $course = $this->course();
        $buyer = User::factory()->create(['account_type' => 'reader']);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/creators/products/{$course->id}/quote")->assertOk()->assertJsonPath('data.amount_to_pay_usd', 10);
        $res = $this->postJson("/api/creators/products/{$course->id}/buy", ['pay_in' => 'USD'])->assertOk()
            ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.amount', 10)
            ->assertJsonPath('data.authorization_url', 'https://checkout.stripe.com/c/pay/cs_test_1');

        $this->assertSame("https://sbareads.com/learn/{$course->id}?paid=1", $this->sessions[0]['success_url']);
        $this->assertSame(1000, $this->sessions[0]['amount']);
        $transaction = Transaction::firstWhere('reference', $res->json('data.reference'));
        $this->assertSame('USD', $transaction->currency);

        $this->cardPaid($transaction, 1000);

        $this->assertTrue($course->purchasedBy($buyer));
        $this->assertEquals(14625, $this->creatorWallet($course)); // ₦15,000 less the 2.5% fee, as for a naira sale
    }

    #[Test]
    public function a_card_payment_for_less_than_the_price_grants_nothing(): void
    {
        $course = $this->course();
        $buyer = User::factory()->create(['account_type' => 'reader']);
        Sanctum::actingAs($buyer);
        $ref = $this->postJson("/api/creators/products/{$course->id}/buy", ['pay_in' => 'USD'])->json('data.reference');

        $this->cardPaid(Transaction::firstWhere('reference', $ref), 500);

        $this->assertFalse($course->purchasedBy($buyer));
        $this->assertEquals(0, $this->creatorWallet($course));
    }

    #[Test]
    public function instalments_stay_with_naira_payments(): void
    {
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create(['account_type' => 'reader']));

        $this->postJson("/api/creators/products/{$course->id}/buy", ['pay_in' => 'USD', 'instalments' => 3])->assertStatus(422);
        $this->assertSame([], $this->sessions);
    }

    #[Test]
    public function a_website_book_purchase_in_dollars_opens_the_card_page(): void
    {
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create([
            'author_id' => $author->id, 'visibility' => 'public', 'status' => 'approved', 'archived' => false,
            'actual_price' => 20, 'discounted_price' => null,
        ]);
        Sanctum::actingAs(User::factory()->create(['account_type' => 'reader']));

        $this->postJson('/api/books/purchase', ['books' => [$book->id], 'currency' => 'USD', 'checkout' => 'web', 'return_path' => "/checkout/{$book->id}"])
            ->assertOk()->assertJsonPath('data.authorization_url', 'https://checkout.stripe.com/c/pay/cs_test_1');

        $this->assertSame(2000, $this->sessions[0]['amount']);
        $this->assertSame("https://sbareads.com/checkout/{$book->id}?paid=1", $this->sessions[0]['success_url']);
        $this->assertDatabaseHas('transactions', ['purpose_type' => 'digital_book_purchase', 'currency' => 'USD', 'payment_provider' => 'stripe']);
    }

    #[Test]
    public function the_card_page_only_ever_returns_to_our_own_website(): void
    {
        config(['services.web.url' => 'https://sbareads.com']);
        foreach (['//evil.example/x', 'https://evil.example', '/x\\@evil', 'javascript:alert(1)', '/ok/../../x?<a>'] as $bad) {
            $this->assertStringStartsWith('https://sbareads.com/library', WebCheckout::urls($bad)['success_url'], $bad);
        }
        $this->assertSame('https://sbareads.com/checkout/7?type=audio&paid=1', WebCheckout::urls('/checkout/7?type=audio')['success_url']);
    }
}
