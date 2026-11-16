<?php

namespace Tests\Feature;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
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

/** Event tickets on SBA Creators: selling, limits, tickets and check-in. */
class SbaCreatorsEventsTest extends TestCase
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
        $this->buyer = User::factory()->create(['account_type' => 'reader', 'email' => 'guest@example.com']);

        $this->mock(PaymentService::class)->shouldReceive('createPayment')->andReturnUsing(fn (array $data, User $user) => Transaction::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'reference' => uniqid('test_'),
            'amount' => $data['amount'], 'currency' => $data['currency'], 'payment_provider' => 'paystack',
            'purpose_type' => $data['purpose'], 'purpose_id' => $data['purpose_id'], 'meta_data' => $data['meta_data'] ?? [],
            'status' => 'pending', 'type' => 'purchase', 'direction' => 'debit',
            'payment_client_secret' => 'https://checkout.paystack.com/' . uniqid(),
        ]));
    }

    private function event(array $fields = [], bool $publish = true): CreatorProduct
    {
        Sanctum::actingAs($this->creator);
        $this->postJson('/api/creators/studio/store', ['name' => 'Grace Academy'])->assertCreated();
        $id = $this->postJson('/api/creators/studio/products', $fields + [
            'type' => 'event', 'title' => 'Writers Conference', 'price' => 5000,
            'starts_at' => now()->addDays(10)->toISOString(), 'timezone' => 'Africa/Lagos',
            'location' => 'Lagos', 'online_url' => 'https://meet.example.com/abc',
        ])->assertCreated()->json('data.product.id');
        if ($publish) {
            $this->postJson("/api/creators/studio/products/{$id}", ['status' => 'published'])->assertOk();
        }

        return CreatorProduct::find($id);
    }

    private function confirm(CreatorProduct $product): void
    {
        $service = app(PaystackWebhookService::class);
        $process = new \ReflectionMethod($service, 'processSuccessfulTransaction');
        foreach (Transaction::where('purpose_type', 'creator_purchase')->get() as $transaction) {
            $process->invoke($service, $transaction->fresh(), []);
        }
    }

    #[Test]
    public function an_event_needs_a_future_date_and_a_place_to_publish(): void
    {
        $product = $this->event(['starts_at' => null, 'location' => null, 'online_url' => null], publish: false);

        $this->postJson("/api/creators/studio/products/{$product->id}", ['status' => 'published'])
            ->assertStatus(422)->assertJsonPath('error', 'Set a date and time in the future before publishing.');
        $this->postJson("/api/creators/studio/products/{$product->id}", ['status' => 'published', 'starts_at' => now()->addDay()->toISOString()])
            ->assertStatus(422)->assertJsonPath('error', 'Add where it happens: a venue, an online link, or both.');
        $this->postJson("/api/creators/studio/products/{$product->id}", ['status' => 'published', 'starts_at' => now()->addDay()->toISOString(), 'online_url' => 'https://zoom.us/j/1'])->assertOk();
    }

    #[Test]
    public function the_joining_link_is_only_shown_to_ticket_holders(): void
    {
        $product = $this->event();

        $this->getJson('/api/creators/stores/grace-academy/products/' . $product->slug)
            ->assertJsonPath('data.product.is_online', true)
            ->assertJsonPath('data.product.spots_left', null)
            ->assertJsonMissingPath('data.product.online_url');
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/creators/products/{$product->id}/content")->assertJsonMissingPath('data.product.online_url')->assertJsonPath('data.ticket', null);
    }

    #[Test]
    public function a_paid_ticket_gets_a_code_and_an_email(): void
    {
        $product = $this->event();
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertOk();
        $this->confirm($product);

        $ticket = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $ticket->ticket_code);
        $this->getJson("/api/creators/products/{$product->id}/content")
            ->assertJsonPath('data.ticket.code', $ticket->ticket_code)
            ->assertJsonPath('data.product.online_url', 'https://meet.example.com/abc');
        Mail::assertQueued(GenericAppNotification::class, fn ($mail) => $mail->hasTo('guest@example.com')
            && str_contains($mail->title, 'Your ticket') && str_contains($mail->body, $ticket->ticket_code));
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertStatus(409)->assertJsonPath('error', 'You already have a ticket.');
    }

    #[Test]
    public function a_full_event_stops_selling(): void
    {
        $product = $this->event(['price' => 0, 'capacity' => 1]);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertStatus(422)->assertJsonPath('error', 'Sorry, this event is sold out.');
        $this->assertSame(0, $product->fresh()->spotsLeft());
    }

    #[Test]
    public function the_limit_cannot_go_below_tickets_sold(): void
    {
        $product = $this->event(['price' => 0, 'capacity' => 5]);
        foreach (range(1, 2) as $i) {
            Sanctum::actingAs(User::factory()->create());
            $this->postJson("/api/creators/products/{$product->id}/buy")->assertCreated();
        }
        Sanctum::actingAs($this->creator);
        $this->postJson("/api/creators/studio/products/{$product->id}", ['capacity' => 1])->assertStatus(422);
        $this->postJson("/api/creators/studio/products/{$product->id}", ['capacity' => 2])->assertOk();
    }

    #[Test]
    public function tickets_stop_selling_once_the_event_has_passed(): void
    {
        $product = $this->event();
        $product->update(['starts_at' => now()->subHour()]);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertStatus(422)->assertJsonPath('error', 'This event has already taken place.');
    }

    #[Test]
    public function the_creator_checks_people_in_once(): void
    {
        $product = $this->event(['price' => 0]);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/creators/products/{$product->id}/buy")->assertCreated();
        $code = CreatorPurchase::firstWhere('buyer_id', $this->buyer->id)->ticket_code;

        // Someone else can't check people in.
        $this->postJson("/api/creators/studio/products/{$product->id}/check-in", ['code' => $code])->assertNotFound();

        Sanctum::actingAs($this->creator);
        $this->getJson("/api/creators/studio/products/{$product->id}/attendees")
            ->assertJsonPath('data.sold', 1)->assertJsonPath('data.checked_in', 0)->assertJsonPath('data.tickets.0.code', $code);
        $this->postJson("/api/creators/studio/products/{$product->id}/check-in", ['code' => 'nope'])->assertNotFound();
        $this->postJson("/api/creators/studio/products/{$product->id}/check-in", ['code' => strtolower(substr($code, 0, 4) . '-' . substr($code, 4))])
            ->assertOk()->assertJsonPath('data.name', $this->buyer->name);
        $this->postJson("/api/creators/studio/products/{$product->id}/check-in", ['code' => $code])->assertStatus(409);
        $this->getJson("/api/creators/studio/products/{$product->id}/attendees")->assertJsonPath('data.checked_in', 1);
    }
}
