<?php

namespace Tests\Feature;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSlot;
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

/** Coaching sessions and monthly memberships on SBA Creators. */
class SbaCreatorsCoachingMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();
        $this->creator = User::factory()->create(['account_type' => 'author', 'kyc_status' => 'verified']);
        $this->buyer = User::factory()->create(['account_type' => 'reader', 'email' => 'buyer@example.com']);

        $this->mock(PaymentService::class)->shouldReceive('createPayment')->andReturnUsing(fn (array $data, User $user) => Transaction::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'reference' => uniqid('test_'),
            'amount' => $data['amount'], 'currency' => $data['currency'], 'payment_provider' => 'paystack',
            'purpose_type' => $data['purpose'], 'purpose_id' => $data['purpose_id'], 'meta_data' => $data['meta_data'] ?? [],
            'status' => 'pending', 'type' => 'purchase', 'direction' => 'debit',
            'payment_client_secret' => 'https://checkout.paystack.com/' . uniqid(),
        ]));
    }

    private function openStore(): void
    {
        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'])->assertCreated();
    }

    private function coaching(float $price = 10000, int $slots = 2): CreatorProduct
    {
        $this->openStore();
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'coaching', 'title' => 'Book Coaching Call', 'price' => $price, 'online_url' => 'https://meet.example.com/coach'])
            ->assertCreated()->assertJsonPath('data.product.duration_minutes', 60)->json('data.product.id');
        if ($slots) {
            $times = array_map(fn ($d) => now()->addDays($d)->setTime(10, 0)->toISOString(), range(1, $slots));
            $this->postJson("/api/creators/studio/products/{$id}/slots", ['starts_at' => $times])->assertCreated()->assertJsonPath('data.added', $slots);
            $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])->assertOk();
        }

        return CreatorProduct::find($id);
    }

    private function membership(float $price = 3000): CreatorProduct
    {
        $this->openStore();
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'membership', 'title' => 'Writers Circle', 'price' => $price])->json('data.product.id');
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'Welcome', 'kind' => 'text', 'body' => 'Members only words'])->assertCreated();
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
    public function coaching_needs_free_times_to_publish(): void
    {
        $product = $this->coaching(slots: 0);

        $this->postJson("/api/creators/studio/products/{$product->id}", ['status' => 'published'])
            ->assertStatus(422)->assertJsonPath('error', 'Add at least one time you are free before publishing.');
        $this->postJson("/api/creators/studio/products/{$product->id}/slots", ['starts_at' => [now()->subDay()->toISOString()]])->assertStatus(422);
    }

    #[Test]
    public function booking_a_paid_time_holds_it_then_books_it(): void
    {
        $product = $this->coaching();
        $slot = $product->slots()->first();

        $this->getJson('/api/creators/stores/grace-academy/products/book-coaching-call')
            ->assertJsonCount(2, 'data.product.slots')->assertJsonMissingPath('data.product.online_url');

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertStatus(422);
        $this->postJson("/api/creators/products/{$product->id}/buy", ['slot_id' => $slot->id])->assertOk();
        $this->assertNotNull($slot->fresh()->held_until);

        // Someone else can't take a held time.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/creators/products/{$product->id}/buy", ['slot_id' => $slot->id])->assertStatus(409);
        $this->getJson('/api/creators/stores/grace-academy/products/book-coaching-call')->assertJsonCount(1, 'data.product.slots');

        $this->confirmAll();
        $this->assertNotNull($slot->fresh()->booked_at);
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/creators/products/{$product->id}/content")
            ->assertJsonPath('data.has_access', true)
            ->assertJsonPath('data.product.online_url', 'https://meet.example.com/coach')
            ->assertJsonPath('data.bookings.0.starts_at', $slot->starts_at->toISOString());
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => $m->hasTo('buyer@example.com') && str_contains($m->title, 'Your session is booked'));

        // Coaching can be booked again.
        $this->postJson("/api/creators/products/{$product->id}/buy", ['slot_id' => $product->slots()->skip(1)->first()->id])->assertOk();
    }

    #[Test]
    public function an_expired_hold_frees_the_time(): void
    {
        $product = $this->coaching();
        $slot = $product->slots()->first();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy", ['slot_id' => $slot->id])->assertOk();
        $slot->update(['held_until' => now()->subMinute()]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/creators/products/{$product->id}/buy", ['slot_id' => $slot->id])->assertOk();
    }

    #[Test]
    public function a_paid_session_whose_time_was_taken_can_pick_another(): void
    {
        $product = $this->coaching();
        [$first, $second] = $product->slots()->get()->all();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy", ['slot_id' => $first->id])->assertOk();
        // The hold ran out and someone else booked the time before this payment confirmed.
        $first->update(['held_until' => null, 'booked_at' => now(), 'purchase_id' => 999999]);
        $this->confirmAll();

        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);
        $this->assertSame('paid', $purchase->status);
        $this->assertNull($purchase->slot_id);
        $this->getJson("/api/creators/products/{$product->id}/content")->assertJsonPath('data.bookings.0.starts_at', null);

        $this->postJson("/api/creators/bookings/{$purchase->id}/book", ['slot_id' => $first->id])->assertStatus(409);
        $this->postJson("/api/creators/bookings/{$purchase->id}/book", ['slot_id' => $second->id])->assertOk();
        $this->assertSame($second->id, $purchase->fresh()->slot_id);
    }

    #[Test]
    public function the_coach_sees_bookings_and_cannot_remove_a_booked_time(): void
    {
        $product = $this->coaching(price: 0);
        $slot = $product->slots()->first();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy", ['slot_id' => $slot->id])->assertCreated();

        Sanctum::actingAs($this->creator);
        $this->getJson("/api/creators/studio/products/{$product->id}/slots")
            ->assertJsonPath('data.slots.0.status', 'booked')->assertJsonPath('data.slots.0.name', $this->buyer->name)
            ->assertJsonPath('data.slots.1.status', 'open');
        $this->deleteJson("/api/creators/studio/products/{$product->id}/slots/{$slot->id}")->assertStatus(409);
        $other = $product->slots()->whereKeyNot($slot->id)->first();
        $this->deleteJson("/api/creators/studio/products/{$product->id}/slots/{$other->id}")->assertOk();
        $this->assertSame(1, CreatorSlot::count());
    }

    #[Test]
    public function a_membership_needs_a_monthly_price(): void
    {
        $this->openStore();
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'membership', 'title' => 'Free Circle', 'price' => 0])->json('data.product.id');
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'Hi', 'kind' => 'text', 'body' => 'x'])->assertCreated();
        $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])
            ->assertStatus(422)->assertJsonPath('error', 'Set a monthly price before publishing a membership.');
    }

    #[Test]
    public function membership_gives_a_month_and_renewing_early_adds_on(): void
    {
        $product = $this->membership();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk();
        $this->confirmAll();

        $until = $product->memberUntil($this->buyer);
        $this->assertTrue($until->between(now()->addDays(29), now()->addDays(31)));
        $this->getJson("/api/creators/products/{$product->id}/content")
            ->assertJsonPath('data.has_access', true)->assertJsonPath('data.lessons.0.body', 'Members only words');

        // Renewing while still a member starts the new month when the current one ends.
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk();
        $this->confirmAll();
        $this->assertTrue($product->memberUntil($this->buyer)->between(now()->addDays(59), now()->addDays(61)));
        $this->assertSame(2, Transaction::where('purpose_type', 'creator_sale')->count());

        Sanctum::actingAs($this->creator);
        $this->getJson("/api/creators/studio/products/{$product->id}/members")
            ->assertJsonPath('data.active', 1)->assertJsonPath('data.members.0.months', 2);
    }

    #[Test]
    public function an_ended_membership_loses_access_and_reminders_go_out_once(): void
    {
        $product = $this->membership();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk();
        $this->confirmAll();
        $purchase = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);

        $purchase->update(['access_until' => now()->addDays(2)]);
        $this->artisan('creators:membership-reminders')->assertSuccessful();
        $this->artisan('creators:membership-reminders')->assertSuccessful();
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => str_contains($m->title, 'ends soon'));
        $this->assertSame(1, Mail::queued(GenericAppNotification::class, fn ($m) => str_contains($m->title, 'ends soon'))->count());

        $purchase->update(['access_until' => now()->subHour()]);
        $this->artisan('creators:membership-reminders')->assertSuccessful();
        Mail::assertQueued(GenericAppNotification::class, fn ($m) => str_contains($m->title, 'has ended'));
        $this->getJson("/api/creators/products/{$product->id}/content")
            ->assertJsonPath('data.has_access', false)->assertJsonPath('data.lessons.0.body', null);
        $this->getJson('/api/creators/library')->assertJsonPath('data.products.0.member_until', $purchase->fresh()->access_until->toISOString());
    }
}
