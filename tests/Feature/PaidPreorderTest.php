<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\DigitalBookPurchase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Book\BookPurchaseService;
use App\Services\Paystack\PaystackWebhookService;
use App\Services\Stripe\StripeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaidPreorderTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();
        Notification::fake();

        $this->reader = User::factory()->create(['account_type' => 'reader']);
        $this->author = User::factory()->create(['account_type' => 'author', 'wallet_balance' => 0]);
    }

    private function book(bool $unreleased): Book
    {
        return Book::factory()->create([
            'author_id' => $this->author->id,
            'actual_price' => 10,
            'is_preorder' => $unreleased,
            'release_at' => $unreleased ? now()->addWeek() : null,
        ]);
    }

    /** A paid purchase as the checkout leaves it, then confirmed by the payment provider. */
    private function pay(string $provider, Book $book): DigitalBookPurchase
    {
        $purchase = DigitalBookPurchase::create([
            'user_id' => $this->reader->id,
            'total_amount' => 10,
            'platform_fee_amount' => 2.5,
            'currency' => 'USD',
            'status' => 'pending',
        ]);
        $purchase->items()->create([
            'book_id' => $book->id,
            'author_id' => $this->author->id,
            'quantity' => 1,
            'price_at_purchase' => 10,
            'price_at_purchase_usd' => 10,
            'author_payout_amount' => 7.5,
            'author_payout_amount_usd' => 7.5,
            'platform_fee_amount' => 2.5,
            'platform_fee_amount_usd' => 2.5,
            'payout_status' => 'pending',
            'currency' => 'USD',
        ]);
        $transaction = Transaction::factory()->create([
            'user_id' => $this->reader->id,
            'purpose_type' => 'digital_book_purchase',
            'purpose_id' => $purchase->id,
            'status' => 'succeeded',
        ]);

        $this->confirm($provider, $transaction);

        return $purchase->fresh();
    }

    /** Follows the webhook path, including Paystack's check for an already-fulfilled purchase. */
    private function confirm(string $provider, Transaction $transaction): void
    {
        $service = app($provider === 'paystack' ? PaystackWebhookService::class : StripeWebhookService::class);
        if ($provider === 'paystack') {
            $fulfilled = new \ReflectionMethod($service, 'isPurchaseFulfilled');
            if ($fulfilled->invoke($service, $transaction)) return;
        }
        $method = new \ReflectionMethod($service, 'processSuccessfulDigitalBookPurchase');
        $method->invoke($service, $transaction, $this->reader->fresh());
    }

    private function owns(Book $book): bool
    {
        return app(BookPurchaseService::class)->userOwnsBook($this->reader, $book->id);
    }

    public static function providers(): array
    {
        return [['paystack'], ['stripe']];
    }

    #[Test]
    #[DataProvider('providers')]
    public function paying_for_an_unreleased_book_holds_it_until_release(string $provider): void
    {
        $book = $this->book(unreleased: true);

        $purchase = $this->pay($provider, $book);

        $this->assertSame('preordered', $purchase->status);
        $this->assertFalse($this->owns($book));
        $this->assertSame(7.5, (float) $this->author->fresh()->wallet_balance);

        Sanctum::actingAs($this->reader);
        $this->getJson("/api/books/{$book->id}")->assertOk()
            ->assertJsonPath('data.book.is_preordered', true)
            ->assertJsonPath('data.book.is_purchased', false)
            ->assertJsonPath('data.book.files', []);
    }

    #[Test]
    #[DataProvider('providers')]
    public function a_repeated_confirmation_does_not_pay_the_author_twice(string $provider): void
    {
        $book = $this->book(unreleased: true);
        $purchase = $this->pay($provider, $book);

        $this->confirm($provider, Transaction::where('purpose_id', $purchase->id)->where('purpose_type', 'digital_book_purchase')->first());

        $this->assertSame(7.5, (float) $this->author->fresh()->wallet_balance);
        $this->assertSame('preordered', $purchase->fresh()->status);
    }

    #[Test]
    #[DataProvider('providers')]
    public function a_preorder_unlocks_on_its_release_date(string $provider): void
    {
        $book = $this->book(unreleased: true);
        $purchase = $this->pay($provider, $book);

        $book->update(['release_at' => now()->subMinute()]);
        $this->artisan('books:release-preorders')->assertSuccessful();

        $this->assertTrue($this->owns($book));
        $this->assertSame('paid', $purchase->fresh()->status);
    }

    #[Test]
    #[DataProvider('providers')]
    public function released_books_still_unlock_straight_away(string $provider): void
    {
        $book = $this->book(unreleased: false);

        $purchase = $this->pay($provider, $book);

        $this->assertSame('paid', $purchase->status);
        $this->assertTrue($this->owns($book));
    }

    #[Test]
    public function a_reader_cannot_pay_twice_for_the_same_preorder(): void
    {
        $book = $this->book(unreleased: true);
        $this->pay('paystack', $book);

        Sanctum::actingAs($this->reader);
        $this->postJson('/api/books/purchase', ['books' => [$book->id], 'currency' => 'NGN'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'You have already pre-ordered: ' . $book->title);
    }
}
