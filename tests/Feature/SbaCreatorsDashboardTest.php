<?php

namespace Tests\Feature;

use App\Models\CreatorProduct;
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

/** SBA Creators sales on the author dashboard, in the notification bell, and in AI insights. */
class SbaCreatorsDashboardTest extends TestCase
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
        config(['services.openai.api_key' => null, 'services.anthropic.api_key' => null]);
        $this->creator = User::factory()->create(['account_type' => 'author', 'kyc_status' => 'verified']);
        $this->creator->assignRole(Role::findByName('author', 'api'));
        $this->buyer = User::factory()->create(['account_type' => 'reader']);

        $this->mock(PaymentService::class)->shouldReceive('createPayment')->andReturnUsing(fn (array $data, User $user) => Transaction::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'reference' => uniqid('test_'),
            'amount' => $data['amount'], 'currency' => $data['currency'], 'payment_provider' => 'paystack',
            'purpose_type' => $data['purpose'], 'purpose_id' => $data['purpose_id'], 'meta_data' => $data['meta_data'] ?? [],
            'status' => 'pending', 'type' => 'purchase', 'direction' => 'debit',
            'payment_client_secret' => 'https://checkout.paystack.com/' . uniqid(),
        ]));
    }

    private function sellCourse(): CreatorProduct
    {
        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'])->assertCreated();
        $id = $this->postJson('/api/creators/studio/products', ['type' => 'course', 'title' => 'AI Basics', 'price' => 10000])->json('data.product.id');
        $this->postJson("/api/creators/studio/products/{$id}/lessons", ['title' => 'One', 'kind' => 'text', 'body' => 'x'])->assertCreated();
        $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])->assertOk();

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$id}/buy")->assertOk();
        $service = app(PaystackWebhookService::class);
        $process = new \ReflectionMethod($service, 'processSuccessfulTransaction');
        foreach (Transaction::where('purpose_type', 'creator_purchase')->where('status', 'pending')->get() as $t) {
            $t->update(['status' => 'succeeded']);
            $process->invoke($service, $t->fresh(), []);
        }

        return CreatorProduct::find($id);
    }

    #[Test]
    public function store_sales_count_in_the_dashboard_total_earnings_in_naira(): void
    {
        $this->sellCourse();

        Sanctum::actingAs($this->creator);
        $this->getJson('/api/author/dashboard')->assertOk()
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.revenue', 9750);
    }

    #[Test]
    public function the_creator_and_buyer_get_a_notification_in_their_bell(): void
    {
        $this->sellCourse();

        Sanctum::actingAs($this->creator);
        $this->getJson('/api/user/notifications?per_page=15')->assertOk()
            ->assertJsonPath('data.data.0.title', 'New sale');

        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/user/notifications?per_page=15')->assertOk()
            ->assertJsonPath('data.data.0.title', 'You now have access');
    }

    #[Test]
    public function ai_insights_still_answer_when_no_ai_key_is_set(): void
    {
        $this->sellCourse();

        Sanctum::actingAs($this->creator);
        $insights = $this->getJson('/api/author/ai/insights')->assertOk()->json('data.insights');
        $this->assertStringContainsString('AI Basics', $insights);
        $this->assertStringContainsString('NGN 9,750.00', $insights);
    }
}
