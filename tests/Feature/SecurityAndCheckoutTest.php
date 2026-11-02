<?php

namespace Tests\Feature;

use App\Mail\Generic\ApplicationReceived;
use App\Models\Application;
use App\Models\Book;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Paystack\CurrencyConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityAndCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_AUTHOR_FIELDS = [
        'email', 'mfa_secret', 'device_token', 'kyc_info', 'kyc_metadata', 'kyc_account_id',
        'kyc_customer_id', 'preferences', 'settings', 'last_login_at', 'voice_sample_url',
    ];

    private User $author;
    private Book $book;
    private string $lastResetCode = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }

        $this->author = User::factory()->create([
            'account_type' => 'author',
            'mfa_secret' => 'SECRETVALUE',
            'device_token' => 'DEVICETOKEN',
        ]);
        $this->book = Book::factory()->create([
            'author_id' => $this->author->id,
            'visibility' => 'public',
            'status' => 'approved',
            'archived' => false,
            'actual_price' => 20,
            'discounted_price' => null,
            'files' => [['public_id' => 'x.pdf', 'public_url' => 'https://files.example/x.pdf']],
            'audio_url' => 'https://files.example/a.mp3',
            'audio_segments' => ['https://files.example/a1.mp3'],
            'audio_sample_url' => 'https://files.example/sample.mp3',
            'audio_status' => 'ready',
        ]);
        $this->book->authors()->syncWithoutDetaching([$this->author->id]);
    }

    private function reader(): User
    {
        return User::factory()->create(['account_type' => 'reader']);
    }

    private function bookFromPublicList(): array
    {
        $response = $this->getJson('/api/books?items_per_page=50')->assertOk();

        return collect($response->json('data.data'))->firstWhere('id', $this->book->id);
    }

    #[Test]
    public function public_book_list_exposes_no_private_author_data_or_paid_content(): void
    {
        $response = $this->getJson('/api/books?items_per_page=50')->assertOk();
        $this->assertStringNotContainsString('SECRETVALUE', $response->getContent());
        $this->assertStringNotContainsString('DEVICETOKEN', $response->getContent());
        $this->assertStringNotContainsString($this->author->email, $response->getContent());
        $this->assertStringNotContainsString('files.example/x.pdf', $response->getContent());
        $this->assertStringNotContainsString('files.example/a1.mp3', $response->getContent());

        $book = collect($response->json('data.data'))->firstWhere('id', $this->book->id);
        foreach (self::PRIVATE_AUTHOR_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $book['author']);
            $this->assertArrayNotHasKey($field, $book['authors'][0]);
        }
        $this->assertSame($this->author->name, $book['author']['name']);
        $this->assertSame([], $book['files']);
        $this->assertNull($book['audio_url']);
        $this->assertSame('https://files.example/sample.mp3', $book['audio_sample_url']);
        $this->assertSame([], $book['readers']);
        $this->assertSame([], $book['bookmarks']);
    }

    #[Test]
    public function signed_in_non_owner_cannot_get_files_or_other_users_ids(): void
    {
        $owner = $this->reader();
        DB::table('book_user')->insert(['user_id' => $owner->id, 'book_id' => $this->book->id, 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($this->reader());
        $book = $this->getJson("/api/books/{$this->book->id}")->assertOk()->json('data.book');

        $this->assertSame([], $book['files']);
        $this->assertSame([], $book['audio_segments']);
        $this->assertSame([], $book['readers']);
        $this->assertFalse($book['is_purchased']);
    }

    #[Test]
    public function owner_keeps_file_access_on_detail_and_on_the_public_list(): void
    {
        $owner = $this->reader();
        DB::table('book_user')->insert(['user_id' => $owner->id, 'book_id' => $this->book->id, 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($owner);
        $detail = $this->getJson("/api/books/{$this->book->id}")->assertOk()->json('data.book');
        $this->assertSame('https://files.example/x.pdf', $detail['files'][0]['public_url']);
        $this->assertTrue($detail['is_purchased']);
        $this->assertSame([$owner->id], $detail['readers']);

        $this->assertSame('https://files.example/x.pdf', $this->bookFromPublicList()['files'][0]['public_url']);
    }

    #[Test]
    public function author_and_staff_keep_full_access(): void
    {
        Sanctum::actingAs($this->author);
        $own = $this->getJson("/api/books/{$this->book->id}")->assertOk()->json('data.book');
        $this->assertNotEmpty($own['files']);
        $this->assertSame($this->author->email, $own['authors'][0]['email']);
        $this->assertSame('DEVICETOKEN', $own['authors'][0]['device_token']);

        $admin = User::factory()->create(['account_type' => 'reader']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
        $staff = $this->getJson("/api/books/{$this->book->id}")->assertOk()->json('data.book');
        $this->assertNotEmpty($staff['files']);
        $this->assertSame($this->author->email, $staff['authors'][0]['email']);
        $this->assertArrayNotHasKey('mfa_secret', $staff['authors'][0]);
        $this->assertNull($staff['authors'][0]['device_token']);
    }

    #[Test]
    public function raw_models_never_serialize_secrets_or_file_links(): void
    {
        $user = $this->author->fresh()->toArray();
        $this->assertArrayNotHasKey('mfa_secret', $user);
        $this->assertArrayNotHasKey('device_token', $user);

        $book = $this->book->fresh()->toArray();
        foreach (['files', 'audio_url', 'audio_segments'] as $field) {
            $this->assertArrayNotHasKey($field, $book);
        }
    }

    private function mockPayments(?callable $inspect = null): void
    {
        $conversion = Mockery::mock(CurrencyConversionService::class);
        $conversion->shouldReceive('convert')->andReturnUsing(fn ($amount) => (float) $amount);
        $this->app->instance(CurrencyConversionService::class, $conversion);

        $payments = Mockery::mock(PaymentService::class);
        $payments->shouldReceive('createPayment')->andReturnUsing(function (array $data) use ($inspect) {
            $inspect && $inspect($data);

            return new \App\Models\Transaction([
                'payment_intent_id' => 'pi_test',
                'payment_client_secret' => 'https://checkout.example/pay',
            ]);
        });
        $this->app->instance(PaymentService::class, $payments);
    }

    #[Test]
    public function starting_a_purchase_does_not_reveal_files_before_payment(): void
    {
        $this->mockPayments();
        Sanctum::actingAs($this->reader());

        $response = $this->postJson('/api/books/purchase', ['books' => [$this->book->id], 'currency' => 'NGN'])->assertOk();

        $this->assertStringNotContainsString('files.example/x.pdf', $response->getContent());
        $this->assertStringNotContainsString('files.example/a1.mp3', $response->getContent());
        $this->assertStringNotContainsString($this->author->email, $response->getContent());
        $this->assertSame('https://checkout.example/pay', $response->json('data.authorization_url'));
    }

    #[Test]
    public function checkout_charges_the_sale_price(): void
    {
        $charged = null;
        $this->mockPayments(function (array $data) use (&$charged) { $charged = $data['amount']; });
        $this->book->update(['discounted_price' => 15]);
        Sanctum::actingAs($this->reader());

        $this->postJson('/api/books/purchase', ['books' => [$this->book->id], 'currency' => 'NGN'])->assertOk();

        $this->assertEquals(15, $charged);
        $this->assertDatabaseHas('digital_book_purchase_items', [
            'book_id' => $this->book->id,
            'price_at_purchase_usd' => 15,
            'author_payout_amount_usd' => 11.25,
        ]);
    }

    #[Test]
    public function a_discounted_price_above_the_list_price_is_ignored(): void
    {
        $this->book->update(['discounted_price' => 30]);
        $this->assertEquals(20, $this->book->fresh()->sellingPrice());
    }

    #[Test]
    public function free_books_are_granted_without_payment(): void
    {
        $this->mockPayments(fn () => $this->fail('Free books must not start a payment.'));
        $this->book->update(['actual_price' => 0]);
        $reader = $this->reader();
        Sanctum::actingAs($reader);

        $this->postJson('/api/books/purchase', ['books' => [$this->book->id], 'currency' => 'NGN'])
            ->assertOk()
            ->assertJsonPath('data.granted', true);

        $this->assertDatabaseHas('book_user', ['user_id' => $reader->id, 'book_id' => $this->book->id]);
    }

    private function requestResetCode(string $email): string
    {
        Mail::shouldReceive('send')->andReturnUsing(function ($view, $data) {
            $this->lastResetCode = (string) ($data['otp'] ?? $this->lastResetCode);
        });
        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();

        return $this->lastResetCode;
    }

    private function newPassword(array $extra): array
    {
        return ['password' => 'Strong#Pass1', 'password_confirmation' => 'Strong#Pass1', ...$extra];
    }

    #[Test]
    public function password_reset_works_end_to_end_and_signs_out_other_sessions(): void
    {
        $user = $this->reader();
        $user->createToken('old-session');
        $code = $this->requestResetCode($user->email);

        $token = $this->postJson('/api/auth/verify-reset-password-otp', ['email' => $user->email, 'otp' => $code])
            ->assertOk()->json('data.reset_token');
        $this->assertNotEmpty($token);

        $this->postJson('/api/auth/reset-password', $this->newPassword(['email' => $user->email, 'reset_token' => $token]))->assertOk();

        $this->assertTrue(Hash::check('Strong#Pass1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        // A reset token works once.
        $this->postJson('/api/auth/reset-password', $this->newPassword(['email' => $user->email, 'reset_token' => $token]))->assertStatus(400);
    }

    #[Test]
    public function knowing_an_email_is_not_enough_to_reset_after_someone_verifies_a_code(): void
    {
        $user = $this->reader();
        $original = $user->password;
        $code = $this->requestResetCode($user->email);
        $this->postJson('/api/auth/verify-reset-password-otp', ['email' => $user->email, 'otp' => $code])->assertOk();

        $this->postJson('/api/auth/reset-password', $this->newPassword(['email' => $user->email]))->assertStatus(400);
        $this->postJson('/api/auth/reset-password', $this->newPassword(['email' => $user->email, 'reset_token' => 'guess']))->assertStatus(400);

        $this->assertSame($original, $user->fresh()->password);
    }

    #[Test]
    public function a_code_only_works_for_its_own_email_and_locks_after_too_many_guesses(): void
    {
        $victim = $this->reader();
        $attacker = $this->reader();
        $code = $this->requestResetCode($victim->email);

        $this->postJson('/api/auth/verify-reset-password-otp', ['email' => $attacker->email, 'otp' => $code])->assertStatus(400);

        $wrong = $code === '111111' ? '222222' : '111111';
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/verify-reset-password-otp', ['email' => $victim->email, 'otp' => $wrong])->assertStatus(400);
        }
        $this->postJson('/api/auth/verify-reset-password-otp', ['email' => $victim->email, 'otp' => $code])->assertStatus(400);
    }

    #[Test]
    public function forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        Mail::shouldReceive('send')->never();
        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $this->assertStringContainsString('If an account exists', $response->json('message'));
    }

    #[Test]
    public function older_apps_are_rejected_unless_the_legacy_flow_is_switched_on(): void
    {
        $user = $this->reader();
        $code = $this->requestResetCode($user->email);
        $this->postJson('/api/auth/verify-reset-password-otp', ['otp' => $code])->assertStatus(400);

        config(['auth.password_reset.allow_legacy_flow' => true]);
        Cache::flush();
        $code = $this->requestResetCode($user->email);
        $this->postJson('/api/auth/verify-reset-password-otp', ['otp' => $code])->assertOk();
        $this->postJson('/api/auth/reset-password', $this->newPassword(['email' => $user->email]))->assertOk();
        $this->assertTrue(Hash::check('Strong#Pass1', $user->fresh()->password));
    }

    private function listingApplication(array $overrides = []): array
    {
        return [
            'type' => 'listing',
            'book_title' => 'My Book',
            'author_name' => 'Test Author',
            'email' => 'author@example.com',
            'format' => 'Paperback',
            'consent' => '1',
            'cover' => UploadedFile::fake()->image('cover.png'),
            'documents' => [UploadedFile::fake()->create('layout.pdf', 200, 'application/pdf')],
            ...$overrides,
        ];
    }

    #[Test]
    public function an_application_is_stored_privately_and_notifies_the_team(): void
    {
        Storage::fake('s3');
        Mail::fake();

        $id = $this->post('/api/applications', $this->listingApplication(), ['Accept' => 'application/json'])
            ->assertStatus(201)->json('data.id');

        $application = Application::findOrFail($id);
        $this->assertSame('new', $application->status);
        $this->assertCount(2, $application->files);
        Storage::disk('s3')->assertExists($application->files[0]['path']);
        Mail::assertSent(ApplicationReceived::class, fn ($mail) => $mail->hasTo(config('services.applications.notify_email')));
    }

    #[Test]
    public function applications_validate_files_and_catch_bots(): void
    {
        Storage::fake('s3');
        Mail::fake();
        $headers = ['Accept' => 'application/json'];

        $this->post('/api/applications', $this->listingApplication(['cover' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')]), $headers)->assertStatus(422);
        $this->post('/api/applications', $this->listingApplication(['consent' => '0']), $headers)->assertStatus(422);
        $this->post('/api/applications', ['type' => 'listing', 'book_title' => 'x'], $headers)->assertStatus(422);

        $this->post('/api/applications', $this->listingApplication(['website' => 'http://spam.example']), $headers)->assertStatus(201);
        $this->assertSame(0, Application::count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function isbn_applications_need_no_files_and_only_staff_can_read_applications(): void
    {
        Storage::fake('s3');
        Mail::fake();
        $id = $this->post('/api/applications', [
            'type' => 'isbn', 'book_title' => 'My Book', 'author_name' => 'A', 'email' => 'a@example.com',
            'format' => 'E-Book', 'package' => 'Single ISBN', 'genre' => 'Memoir', 'consent' => '1',
        ], ['Accept' => 'application/json'])->assertStatus(201)->json('data.id');

        $this->getJson('/api/applications')->assertStatus(401);
        Sanctum::actingAs($this->reader());
        $this->getJson('/api/applications')->assertStatus(403);

        $admin = $this->reader();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
        $this->getJson("/api/applications/{$id}")->assertOk();
        $this->patchJson("/api/applications/{$id}", ['status' => 'in_review'])->assertOk();
        $this->assertSame('in_review', Application::find($id)->status);
    }
}
