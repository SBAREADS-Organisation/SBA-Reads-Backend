<?php

namespace Tests\Feature;

use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorStore;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Paystack\PaystackWebhookService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SbaCreatorsTest extends TestCase
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

        $this->creator = User::factory()->create(['account_type' => 'author', 'kyc_status' => 'verified']);
        $this->buyer = User::factory()->create(['account_type' => 'reader']);

        // Payments are recorded instead of sent to Paystack.
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

    private function openStore(array $extra = []): CreatorStore
    {
        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'] + $extra)->assertCreated();

        return CreatorStore::firstWhere('user_id', $this->creator->id);
    }

    private function publishedCourse(float $price = 10000, array $storeExtra = []): CreatorProduct
    {
        $this->openStore($storeExtra);
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'course', 'title' => 'Write Your First Book', 'price' => $price])
            ->assertCreated()->json('data.product.id');
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'Welcome', 'kind' => 'text', 'body' => 'Hello writers', 'is_preview' => true])->assertCreated();
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'Chapter one', 'kind' => 'text', 'body' => 'The secret part'])->assertCreated();
        $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])->assertOk();

        return CreatorProduct::find($id);
    }

    /** Runs the same code a confirmed Paystack payment runs. */
    private function confirmPayment(string $purpose, int $purposeId): void
    {
        $service = app(PaystackWebhookService::class);
        $fulfilled = new \ReflectionMethod($service, 'isPurchaseFulfilled');
        $process = new \ReflectionMethod($service, 'processSuccessfulTransaction');
        foreach (Transaction::where('purpose_type', $purpose)->where('purpose_id', $purposeId)->get() as $transaction) {
            if (! $fulfilled->invoke($service, $transaction->fresh())) {
                $process->invoke($service, $transaction->fresh(), []);
            }
        }
    }

    #[Test]
    public function a_new_store_gets_a_free_month_of_pro(): void
    {
        $store = $this->openStore();

        $this->assertSame('grace-academy', $store->slug);
        $this->assertSame('pro', $store->activePlan());
        $this->assertSame(0.025, $store->feeRate('NGN'));
        $this->assertSame(0.045, $store->feeRate('USD'));
        $this->getJson('/api/creators/studio/store')->assertJsonPath('data.store.on_trial', true);
    }

    #[Test]
    public function readers_are_asked_to_use_their_author_account(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/creators/studio/store', ['name' => 'Mine'])->assertForbidden();
    }

    #[Test]
    public function after_the_trial_a_store_drops_to_starter_fees(): void
    {
        $store = $this->openStore();
        $store->update(['trial_ends_at' => now()->subDay()]);

        $this->assertSame('starter', $store->fresh()->activePlan());
        $this->assertSame(0.04, $store->fresh()->feeRate('NGN'));
    }

    #[Test]
    public function paid_products_need_a_verified_identity_but_free_ones_do_not(): void
    {
        $this->creator->update(['kyc_status' => 'pending']);
        $this->openStore();
        $paid = $this->postJson('/api/creators/studio/products', ['type' => 'download', 'title' => 'Workbook', 'price' => 5000])->json('data.product.id');
        $free = $this->postJson('/api/creators/studio/products', ['type' => 'download', 'title' => 'Free guide', 'price' => 0])->json('data.product.id');
        foreach ([$paid, $free] as $id) {
            $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'Notes', 'kind' => 'text', 'body' => 'x'])->assertCreated();
        }

        $this->postJson("/api/creators/studio/products/{$paid}", ['status' => 'published'])->assertStatus(422);
        $this->postJson("/api/creators/studio/products/{$free}", ['status' => 'published'])->assertOk();
    }

    #[Test]
    public function a_product_needs_content_before_publishing(): void
    {
        $this->openStore();
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'course', 'title' => 'Empty', 'price' => 1000])->json('data.product.id');

        $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])->assertStatus(422)
            ->assertJsonPath('error', 'Add at least one lesson before publishing.');
    }

    #[Test]
    public function prices_below_the_minimum_are_rejected(): void
    {
        $this->openStore();
        $this->postJson('/api/creators/studio/products', ['type' => 'course', 'title' => 'Cheap', 'price' => 50])->assertStatus(422);
    }

    #[Test]
    public function the_store_page_shows_published_products_and_only_preview_lessons(): void
    {
        $product = $this->publishedCourse();
        $draft = CreatorProduct::create(['store_id' => $product->store_id, 'user_id' => $this->creator->id, 'type' => 'course', 'title' => 'Draft', 'slug' => 'draft', 'price' => 100, 'status' => 'draft']);

        $this->getJson('/api/creators/stores/grace-academy')->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.title', 'Write Your First Book');
        $this->getJson('/api/creators/stores/grace-academy/products/draft')->assertNotFound();

        Sanctum::actingAs($this->buyer);
        $lessons = $this->getJson("/api/creators/products/{$product->id}/content")->assertOk()->assertJsonPath('data.has_access', false)->json('data.lessons');
        $this->assertSame('Hello writers', $lessons[0]['body']);
        $this->assertArrayNotHasKey('body', $lessons[1]);
    }

    #[Test]
    public function when_the_creator_pays_the_fee_it_comes_out_of_their_earnings(): void
    {
        $product = $this->publishedCourse(10000, ['fee_payer' => 'creator']);
        Sanctum::actingAs($this->buyer);

        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk()->assertJsonPath('data.amount', 10000);
        $purchase = CreatorPurchase::first();
        $this->assertEquals(250, $purchase->fee);
        $this->assertEquals(9750, $purchase->creator_earning);
        $this->assertSame('creator_purchase', $this->payments[0]['purpose']);
    }

    #[Test]
    public function when_the_buyer_pays_the_fee_it_is_added_at_checkout(): void
    {
        $product = $this->publishedCourse(10000, ['fee_payer' => 'buyer']);
        $this->getJson('/api/creators/stores/grace-academy/products/write-your-first-book')->assertJsonPath('data.product.amount_to_pay', 10250);
        Sanctum::actingAs($this->buyer);

        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk()->assertJsonPath('data.amount', 10250);
        $this->assertEquals(10000, CreatorPurchase::first()->creator_earning);
    }

    #[Test]
    public function a_confirmed_payment_unlocks_the_course_and_pays_the_creator_once(): void
    {
        $product = $this->publishedCourse(10000);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk();
        $purchase = CreatorPurchase::first();

        $this->confirmPayment('creator_purchase', $purchase->id);
        $this->confirmPayment('creator_purchase', $purchase->id);

        $this->assertSame('paid', $purchase->fresh()->status);
        $credits = Transaction::where('user_id', $this->creator->id)->where('purpose_type', 'creator_sale');
        $this->assertSame(1, $credits->count());
        $this->assertEquals(9750, $credits->first()->amount);
        $this->assertSame('credit', $credits->first()->direction);
        $this->assertSame('NGN', $credits->first()->currency);

        $this->getJson("/api/creators/products/{$product->id}/content")->assertJsonPath('data.has_access', true)->assertJsonPath('data.lessons.1.body', 'The secret part');
        $this->getJson('/api/creators/library')->assertJsonCount(1, 'data.products');
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertStatus(409);
    }

    #[Test]
    public function free_products_are_added_straight_away(): void
    {
        $product = $this->publishedCourse(0);
        Sanctum::actingAs($this->buyer);

        $this->postJson("/api/creators/products/{$product->id}/buy")->assertCreated();
        $this->assertSame([], $this->payments);
        $this->getJson('/api/creators/library')->assertJsonPath('data.products.0.id', $product->id);
    }

    #[Test]
    public function creators_cannot_buy_their_own_product(): void
    {
        $product = $this->publishedCourse();
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertStatus(409);
    }

    #[Test]
    public function buying_a_plan_extends_it_a_month_at_a_time(): void
    {
        $store = $this->openStore();
        $store->update(['trial_ends_at' => now()->subDay()]);

        $this->postJson('/api/creators/studio/store/plan', ['plan' => 'business'])->assertOk();
        $this->assertStringStartsWith('https://checkout.paystack.com/', Transaction::where('purpose_type', 'creator_plan')->first()->payment_client_secret);
        $this->assertEquals(15000, $this->payments[0]['amount']);
        $this->confirmPayment('creator_plan', $store->id);
        $this->confirmPayment('creator_plan', $store->id);

        $store->refresh();
        $this->assertSame('business', $store->activePlan());
        $this->assertSame(0.015, $store->feeRate('NGN'));
        $this->assertTrue($store->plan_paid_until->between(now()->addDays(29), now()->addDays(31)));

        $this->postJson('/api/creators/studio/store/plan', ['plan' => 'business'])->assertOk();
        $this->confirmPayment('creator_plan', $store->id);
        $this->assertTrue($store->fresh()->plan_paid_until->between(now()->addDays(59), now()->addDays(61)));
    }

    #[Test]
    public function upload_links_only_accept_supported_files(): void
    {
        $product = $this->publishedCourse();

        $this->postJson("/api/creators/studio/products/{$product->id}/upload-url", ['kind' => 'video', 'file_name' => 'talk.exe', 'size' => 100])->assertStatus(422);
        $this->postJson("/api/creators/studio/products/{$product->id}/upload-url", ['kind' => 'pdf', 'file_name' => 'big.pdf', 'size' => 999 * 1024 * 1024])->assertStatus(422);
    }

    #[Test]
    public function lessons_cannot_point_at_another_products_files(): void
    {
        $product = $this->publishedCourse();

        $this->postJson("/api/creators/studio/products/{$product->id}/lessons", [
            'title' => 'Sneaky', 'kind' => 'video', 'file_key' => 'books/content/someone-elses-book.pdf',
        ])->assertStatus(422);
    }

    #[Test]
    public function other_creators_cannot_edit_my_products(): void
    {
        $product = $this->publishedCourse();
        Sanctum::actingAs(User::factory()->create(['account_type' => 'author']));

        $this->postJson("/api/creators/studio/products/{$product->id}", ['title' => 'Hacked'])->assertNotFound();
        $this->getJson("/api/creators/studio/products/{$product->id}")->assertNotFound();
    }

    #[Test]
    public function a_product_with_buyers_is_hidden_instead_of_deleted(): void
    {
        $product = $this->publishedCourse(0);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertCreated();

        Sanctum::actingAs($this->creator);
        $this->deleteJson("/api/creators/studio/products/{$product->id}")->assertOk();
        $this->assertSame('hidden', $product->fresh()->status);

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/creators/products/{$product->id}/content")->assertJsonPath('data.has_access', true);
    }

    #[Test]
    public function the_creator_sees_their_sales_and_earnings(): void
    {
        $product = $this->publishedCourse(10000);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy");
        $this->confirmPayment('creator_purchase', CreatorPurchase::first()->id);

        Sanctum::actingAs($this->creator);
        $this->getJson('/api/creators/studio/store')->assertJsonPath('data.stats.sales', 1)->assertJsonPath('data.stats.earnings', 9750);
        $this->getJson('/api/creators/studio/sales')->assertJsonPath('data.sales.data.0.earning', 9750);
    }

    #[Test]
    public function lesson_progress_is_saved_for_buyers_only(): void
    {
        $product = $this->publishedCourse(0);
        $lesson = $product->lessons()->first();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/lessons/{$lesson->id}/progress", ['completed' => true])->assertNotFound();

        $this->postJson("/api/creators/products/{$product->id}/buy")->assertCreated();
        $this->postJson("/api/creators/lessons/{$lesson->id}/progress", ['completed' => true])->assertOk();
        $this->getJson('/api/creators/library')->assertJsonPath('data.products.0.completed_lessons', 1);
    }
}
