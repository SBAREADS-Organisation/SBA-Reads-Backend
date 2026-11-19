<?php

namespace Tests\Feature;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSlot;
use App\Models\CreatorSubscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Creators\CreatorService;
use App\Services\Payments\PaymentService;
use App\Services\Paystack\PaystackService;
use App\Services\Paystack\PaystackWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Refunds, paid-ticket holds, batched list emails and failure alerts on SBA Creators. */
class SbaCreatorsSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $buyer;

    private User $admin;

    private bool $paystackRefunds = true;

    /** @var string[] */
    private array $refunded = [];

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
        $this->admin = User::factory()->create(['account_type' => 'author']);
        $this->admin->assignRole(Role::findByName('admin', 'api'));

        $this->mock(PaymentService::class)->shouldReceive('createPayment')->andReturnUsing(fn (array $data, User $user) => Transaction::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'reference' => uniqid('test_'),
            'amount' => $data['amount'], 'currency' => $data['currency'], 'payment_provider' => 'paystack',
            'purpose_type' => $data['purpose'], 'purpose_id' => $data['purpose_id'], 'meta_data' => $data['meta_data'] ?? [],
            'status' => 'pending', 'type' => 'purchase', 'direction' => 'debit',
            'payment_client_secret' => 'https://checkout.paystack.com/' . uniqid(),
        ]));
        $this->mock(PaystackService::class)->shouldReceive('refund')->andReturnUsing(function ($reference) {
            $this->refunded[] = $reference;

            return $this->paystackRefunds ? ['status' => true, 'data' => ['status' => 'pending']] : ['status' => false, 'message' => 'Transaction has been fully reversed'];
        });
    }

    private function product(string $type, float $price, array $extra = [], string $title = 'Write Your Book'): CreatorProduct
    {
        Sanctum::actingAs($this->creator);
        if (! \App\Models\CreatorStore::where('user_id', $this->creator->id)->exists()) {
            $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'])->assertCreated();
        }
        $id = $this->postJson('/api/creators/studio/products', ['type' => $type, 'title' => $title, 'price' => $price] + $extra)->json('data.product.id');
        if (in_array($type, ['course', 'download', 'membership'], true)) {
            $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'One', 'kind' => 'text', 'body' => 'x'])->assertCreated();
        }

        return CreatorProduct::find($id);
    }

    private function publish(CreatorProduct $product, array $settings = []): void
    {
        Sanctum::actingAs($this->creator);
        $this->postJson("/api/creators/studio/products/{$product->id}", $settings + ['status' => 'published'])->assertOk();
    }

    private function confirmAll(): void
    {
        $service = app(PaystackWebhookService::class);
        $process = new \ReflectionMethod($service, 'processSuccessfulTransaction');
        foreach (Transaction::where('purpose_type', 'creator_purchase')->where('status', 'pending')->get() as $transaction) {
            $transaction->update(['status' => 'succeeded']);
            $process->invoke($service, $transaction->fresh(), []);
        }
    }

    private function wallet(User $user): float
    {
        return (float) Transaction::where('user_id', $user->id)->where('direction', 'credit')->where('status', 'succeeded')->sum('amount');
    }

    #[Test]
    public function staff_can_refund_through_paystack_and_access_and_earnings_are_taken_back(): void
    {
        $course = $this->product('course', 10000);
        $this->publish($course);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$course->id}/buy")->assertOk();
        $this->confirmAll();
        $this->assertTrue($course->purchasedBy($this->buyer));
        $this->assertEquals(9750, $this->wallet($this->creator));
        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/creators/products/{$course->id}/purchases")->assertOk()->assertJsonPath('data.purchases.data.0.email', 'buyer@example.com');
        $this->postJson("/api/admin/creators/purchases/{$purchase->id}/refund", ['refund_payment' => true, 'reason' => 'Bought by mistake'])->assertOk();

        $this->assertSame('refunded', $purchase->fresh()->status);
        $this->assertFalse($course->purchasedBy($this->buyer));
        $this->assertEquals(0, $this->wallet($this->creator));
        $this->assertCount(1, $this->refunded);
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('buyer@example.com') && str_contains($m->title, 'Refund'));
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('creator@example.com') && str_contains($m->title, 'refunded'));

        // Only staff can do this, and only once.
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/admin/creators/purchases/{$purchase->id}/refund", ['refund_payment' => false])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/creators/purchases/{$purchase->id}/refund", ['refund_payment' => false])->assertStatus(422);
    }

    #[Test]
    public function if_paystack_refuses_the_refund_nothing_changes(): void
    {
        $course = $this->product('course', 10000);
        $this->publish($course);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$course->id}/buy")->assertOk();
        $this->confirmAll();
        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);

        $this->paystackRefunds = false;
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/creators/purchases/{$purchase->id}/refund", ['refund_payment' => true])
            ->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'Nothing was changed'));
        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertTrue($course->purchasedBy($this->buyer));
        $this->assertEquals(9750, $this->wallet($this->creator));
    }

    #[Test]
    public function a_refund_frees_the_coaching_time_and_removes_the_add_on(): void
    {
        $coaching = $this->product('coaching', 0, ['online_url' => 'https://meet.example.com/x']);
        Sanctum::actingAs($this->creator);
        $this->postJson("/api/creators/studio/products/{$coaching->id}/slots", ['starts_at' => [now()->addDay()->toISOString()]])->assertCreated();
        $this->publish($coaching);
        $slot = CreatorSlot::first();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$coaching->id}/buy", ['slot_id' => $slot->id])->assertCreated();
        $this->assertNotNull($slot->fresh()->booked_at);

        $workbook = $this->product('download', 5000, [], 'Workbook');
        $this->publish($workbook);
        $course = $this->product('course', 10000, [], 'Course');
        $this->publish($course, ['upsell_product_id' => $workbook->id, 'upsell_price' => 2000]);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$course->id}/buy", ['upsell' => true])->assertOk();
        $this->confirmAll();
        $this->assertTrue($workbook->purchasedBy($this->buyer));

        Sanctum::actingAs($this->admin);
        $booking = CreatorPurchase::where('product_id', $coaching->id)->first();
        $this->postJson("/api/admin/creators/purchases/{$booking->id}/refund", ['refund_payment' => false])->assertOk();
        $this->assertNull($slot->fresh()->booked_at);

        $addOn = CreatorPurchase::where('product_id', $workbook->id)->first();
        $this->postJson("/api/admin/creators/purchases/{$addOn->id}/refund", ['refund_payment' => true])
            ->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'Refund the main purchase'));
        $main = CreatorPurchase::where('product_id', $course->id)->first();
        $this->postJson("/api/admin/creators/purchases/{$main->id}/refund", ['refund_payment' => true])->assertOk();
        $this->assertFalse($course->purchasedBy($this->buyer));
        $this->assertFalse($workbook->purchasedBy($this->buyer));
    }

    #[Test]
    public function the_last_paid_ticket_is_held_for_whoever_is_paying(): void
    {
        $event = $this->product('event', 5000, ['starts_at' => now()->addWeek()->toISOString(), 'location' => 'Lagos', 'capacity' => 1]);
        $this->publish($event);

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$event->id}/buy")->assertOk();
        $this->postJson("/api/creators/products/{$event->id}/buy")->assertOk(); // the same person retrying keeps their hold

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson("/api/creators/products/{$event->id}/buy")->assertStatus(409)
            ->assertJsonPath('error', 'The last tickets are being paid for right now. Please try again in a few minutes.');

        $this->travel(31)->minutes();
        $this->postJson("/api/creators/products/{$event->id}/buy")->assertOk();
    }

    #[Test]
    public function list_emails_go_out_in_batches(): void
    {
        $course = $this->product('course', 1000);
        $this->publish($course);
        foreach (range(1, 120) as $i) {
            CreatorSubscriber::add($course->store_id, "fan{$i}@example.com", null, 'signup');
        }
        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/broadcasts', ['subject' => 'Hello', 'body' => 'News'])->assertOk()->assertJsonPath('data.recipients', 120);
        Mail::assertQueued(GenericAppNotification::class, 120);
    }

    #[Test]
    public function the_team_is_alerted_when_a_payment_cannot_be_applied(): void
    {
        $course = $this->product('course', 10000);
        $this->publish($course);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$course->id}/buy")->assertOk();

        $this->mock(CreatorService::class)->shouldReceive('fulfilPurchase')->andThrow(new \RuntimeException('Database went away'));
        $this->confirmAll();
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('admin@sbareads.com') && str_contains($m->body, 'Database went away'));
    }

    #[Test]
    public function a_payment_for_less_than_the_price_grants_nothing(): void
    {
        $course = $this->product('course', 10000);
        $this->publish($course);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$course->id}/buy")->assertOk();
        $transaction = Transaction::where('purpose_type', 'creator_purchase')->first();

        $service = app(PaystackWebhookService::class);
        $process = new \ReflectionMethod($service, 'processSuccessfulTransaction');
        $process->invoke($service, $transaction, ['amount' => 100 * 100, 'currency' => 'NGN']);
        $this->assertFalse($course->purchasedBy($this->buyer));
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('admin@sbareads.com') && str_contains($m->title, 'less than the price'));

        $process->invoke($service, $transaction, ['amount' => 10000 * 100, 'currency' => 'NGN']);
        $this->assertTrue($course->purchasedBy($this->buyer));
    }

    #[Test]
    public function joining_links_must_be_web_links_and_csv_exports_are_safe(): void
    {
        $event = $this->product('event', 0, ['starts_at' => now()->addWeek()->toISOString(), 'location' => 'Lagos']);
        $this->postJson("/api/creators/studio/products/{$event->id}", ['online_url' => 'javascript:alert(document.cookie)'])->assertStatus(422);
        $this->postJson("/api/creators/studio/products/{$event->id}", ['online_url' => 'https://meet.google.com/abc-defg-hij'])->assertOk();

        CreatorSubscriber::add($event->store_id, 'fan@example.com', '=HYPERLINK("http://evil.example","click")', 'signup');
        $csv = $this->get('/api/creators/studio/subscribers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);
    }

    #[Test]
    public function one_failing_charge_does_not_stop_the_others(): void
    {
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
            $this->app->forgetInstance('encrypter');
        }
        $course = $this->product('course', 9000, ['instalments_max' => 2]);
        $this->publish($course);
        foreach ([$this->buyer, User::factory()->create()] as $who) {
            $card = \App\Models\CreatorSavedCard::create(['user_id' => $who->id, 'authorization_code' => 'AUTH_' . $who->id, 'email' => $who->email, 'last4' => '4081', 'brand' => 'visa']);
            CreatorPurchase::create([
                'product_id' => $course->id, 'store_id' => $course->store_id, 'buyer_id' => $who->id, 'currency' => 'NGN',
                'status' => 'paid', 'paid_at' => now(), 'price' => 9000, 'fee' => 225, 'amount_paid' => 9000, 'creator_earning' => 8775,
                'fee_payer' => 'creator', 'plan' => 'pro', 'instalment_count' => 2, 'instalments_paid' => 1, 'card_id' => $card->id,
                'instalment_status' => 'active', 'next_instalment_at' => now()->subHour(),
            ]);
        }
        // The first buyer's charge blows up unexpectedly; the second buyer's card is simply declined.
        $calls = 0;
        $this->mock(CreatorService::class)->shouldReceive('chargeSavedCard')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('Paystack timed out');
            }

            return false;
        });

        $this->artisan('creators:charge-due')->assertSuccessful();
        $this->assertSame(2, $calls); // the second buyer was still charged after the first errored
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('admin@sbareads.com') && str_contains($m->title, 'instalment charge errored'));
        $this->assertSame(1, CreatorPurchase::where('instalment_failures', 1)->count());
    }
}
