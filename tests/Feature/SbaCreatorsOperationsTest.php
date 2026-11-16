<?php

namespace Tests\Feature;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorStore;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Creators\CreatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Plan timing, reminders, receipts and staff controls for SBA Creators. */
class SbaCreatorsOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();
        $this->creator = User::factory()->create(['account_type' => 'author', 'kyc_status' => 'verified', 'email' => 'creator@example.com']);
    }

    private function store(array $attributes = []): CreatorStore
    {
        return CreatorStore::create($attributes + [
            'user_id' => $this->creator->id, 'name' => 'Grace Academy', 'slug' => 'grace-academy',
            'fee_payer' => 'creator', 'plan' => 'starter', 'trial_ends_at' => now()->addDays(30),
        ]);
    }

    private function planPayment(CreatorStore $store, string $plan): string
    {
        $reference = uniqid('test_');
        Transaction::create([
            'id' => (string) Str::uuid(), 'user_id' => $this->creator->id, 'reference' => $reference, 'amount' => 7500,
            'currency' => 'NGN', 'payment_provider' => 'paystack', 'purpose_type' => 'creator_plan', 'purpose_id' => $store->id,
            'meta_data' => ['plan' => $plan], 'status' => 'succeeded', 'type' => 'purchase', 'direction' => 'debit',
        ]);

        return $reference;
    }

    private function sentTo(string $email, string $subjectPart): bool
    {
        $found = false;
        Mail::assertQueued(GenericAppNotification::class, function ($mail) use ($email, $subjectPart, &$found) {
            if ($mail->hasTo($email) && str_contains($mail->title, $subjectPart)) {
                $found = true;
            }

            return true;
        });

        return $found;
    }

    #[Test]
    public function paying_for_pro_during_the_free_trial_starts_after_it(): void
    {
        $store = $this->store(['trial_ends_at' => now()->addDays(20)]);

        app(CreatorService::class)->fulfilPlan($store, 'pro', $this->planPayment($store, 'pro'));

        $this->assertTrue($store->fresh()->plan_paid_until->between(now()->addDays(49), now()->addDays(51)));
    }

    #[Test]
    public function upgrading_to_business_during_the_trial_starts_now(): void
    {
        $store = $this->store(['trial_ends_at' => now()->addDays(20)]);

        app(CreatorService::class)->fulfilPlan($store, 'business', $this->planPayment($store, 'business'));

        $store->refresh();
        $this->assertSame('business', $store->activePlan());
        $this->assertTrue($store->plan_paid_until->between(now()->addDays(29), now()->addDays(31)));
    }

    #[Test]
    public function buyers_get_a_receipt_and_creators_get_a_sale_email(): void
    {
        $store = $this->store();
        $product = CreatorProduct::create(['store_id' => $store->id, 'user_id' => $this->creator->id, 'type' => 'course', 'title' => 'Write Your First Book', 'slug' => 'write', 'price' => 5000, 'status' => 'published']);
        $buyer = User::factory()->create(['account_type' => 'reader', 'email' => 'buyer@example.com']);
        $purchase = CreatorPurchase::create([
            'product_id' => $product->id, 'store_id' => $store->id, 'buyer_id' => $buyer->id, 'price' => 5000, 'fee' => 125,
            'amount_paid' => 5000, 'creator_earning' => 4875, 'fee_payer' => 'creator', 'plan' => 'pro', 'currency' => 'NGN', 'status' => 'pending',
        ]);

        app(CreatorService::class)->fulfilPurchase($purchase);

        $this->assertTrue($this->sentTo('buyer@example.com', 'You now have access to "Write Your First Book"'));
        $this->assertTrue($this->sentTo('creator@example.com', 'New sale on SBA Creators'));
    }

    #[Test]
    public function creators_are_reminded_before_their_trial_ends(): void
    {
        $this->store(['trial_ends_at' => now()->addDays(3)->addHours(2)]);

        $this->artisan('creators:plan-reminders')->assertSuccessful();

        $this->assertTrue($this->sentTo('creator@example.com', 'free month of SBA Creators Pro ends in 3 days'));
    }

    #[Test]
    public function creators_are_reminded_before_a_paid_plan_ends_and_told_when_it_has(): void
    {
        $store = $this->store(['trial_ends_at' => now()->subMonth(), 'plan' => 'business', 'plan_paid_until' => now()->addDays(3)->addHours(2)]);
        $this->artisan('creators:plan-reminders')->assertSuccessful();
        $this->assertTrue($this->sentTo('creator@example.com', 'Business plan ends in 3 days'));

        $store->update(['plan_paid_until' => now()->subHours(2)]);
        $this->artisan('creators:plan-reminders')->assertSuccessful();
        $this->assertTrue($this->sentTo('creator@example.com', 'now on Starter'));
    }

    #[Test]
    public function no_reminder_when_a_paid_plan_already_follows_the_trial(): void
    {
        $this->store(['trial_ends_at' => now()->addDays(3)->addHours(2), 'plan' => 'pro', 'plan_paid_until' => now()->addDays(33)]);

        $this->artisan('creators:plan-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    #[Test]
    public function staff_see_creators_and_can_pause_a_store(): void
    {
        $store = $this->store();
        $product = CreatorProduct::create(['store_id' => $store->id, 'user_id' => $this->creator->id, 'type' => 'course', 'title' => 'Course', 'slug' => 'course', 'price' => 5000, 'status' => 'published']);
        $admin = User::factory()->create(['account_type' => 'reader']);
        $admin->assignRole('admin');

        Sanctum::actingAs($this->creator);
        $this->getJson('/api/admin/creators')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/creators')->assertOk()
            ->assertJsonPath('data.totals.stores', 1)
            ->assertJsonPath('data.stores.data.0.owner.email', 'creator@example.com')
            ->assertJsonPath('data.stores.data.0.plan', 'pro');
        $this->getJson("/api/admin/creators/{$store->id}/products")->assertOk()->assertJsonPath('data.products.0.title', 'Course');

        $this->postJson("/api/admin/creators/{$store->id}/suspend", ['suspended' => true])->assertOk();
        $this->getJson('/api/creators/stores/grace-academy')->assertNotFound();
        $this->postJson("/api/admin/creators/{$store->id}/suspend", ['suspended' => false])->assertOk();
        $this->getJson('/api/creators/stores/grace-academy')->assertOk();

        $this->postJson("/api/admin/creators/products/{$product->id}/hide")->assertOk();
        $this->assertSame('hidden', $product->fresh()->status);
    }
}
