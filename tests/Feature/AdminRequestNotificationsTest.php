<?php

namespace Tests\Feature;

use App\Mail\Generic\ApplicationReceived;
use App\Mail\Generic\GenericAppNotification;
use App\Models\Application;
use App\Models\Book;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Book\BookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRequestNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = 'admin@sbareads.com';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['account_type' => 'reader']);
        $admin->assignRole('admin');

        return $admin;
    }

    #[Test]
    public function a_new_book_is_accepted_in_the_web_form_format_and_emails_the_admin_inbox(): void
    {
        $author = User::factory()->create(['account_type' => 'author', 'kyc_status' => 'verified']);
        Sanctum::actingAs($author);

        $book = Book::factory()->create(['author_id' => $author->id, 'title' => 'The Road to Grace']);
        $this->mock(BookService::class)->shouldReceive('createMultiple')->once()->andReturn(collect([$book]));

        $response = $this->post('/api/books', [
            'books' => [[
                'title' => 'A New Title',
                'description' => 'What it is about',
                'actual_price' => '9.99',
                'currency' => 'USD',
                'genres' => ['Faith'],
                'author_id' => $author->id,
                'authors' => [$author->id],
                'meta_data' => ['pages' => '240'],
                'cover_image' => UploadedFile::fake()->create('cover.jpg', 100, 'image/jpeg'),
                'files' => [UploadedFile::fake()->create('book.pdf', 100, 'application/pdf')],
            ]],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        Mail::assertQueued(GenericAppNotification::class, fn ($mail) => $mail->hasTo(self::INBOX) && str_contains($mail->title, 'The Road to Grace'));
    }

    #[Test]
    public function a_usd_payout_request_emails_the_admin_inbox_and_staff_once_each(): void
    {
        $staff = $this->admin();
        $author = User::factory()->create(['account_type' => 'author']);
        Transaction::factory()->create([
            'user_id' => $author->id,
            'amount' => 50,
            'currency' => 'USD',
            'direction' => 'credit',
            'status' => 'succeeded',
            'payment_provider' => 'stripe',
        ]);
        Sanctum::actingAs($author);

        $this->postJson('/api/author/wallet/payout-requests', ['amount' => 10])->assertSuccessful();

        Mail::assertQueued(GenericAppNotification::class, 2);
        Mail::assertQueued(GenericAppNotification::class, fn ($mail) => $mail->hasTo(self::INBOX));
        Mail::assertQueued(GenericAppNotification::class, fn ($mail) => $mail->hasTo($staff->email));
    }

    #[Test]
    public function a_publishing_application_is_saved_emailed_and_fully_visible_to_admins(): void
    {
        Storage::fake('s3');

        $response = $this->post('/api/applications', [
            'type' => 'publishing',
            'book_title' => 'My Manuscript',
            'author_name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'format' => 'Hardcover',
            'consent' => '1',
            'cover' => UploadedFile::fake()->create('cover.jpg', 100, 'image/jpeg'),
            'documents' => [UploadedFile::fake()->create('manuscript.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $id = $response->json('data.id');
        Mail::assertSent(ApplicationReceived::class, fn ($mail) => $mail->hasTo(self::INBOX));

        Sanctum::actingAs($this->admin());
        $listed = $this->getJson('/api/applications?status=new&type=publishing')->assertOk()->json('data.data.0');
        $this->assertSame($id, $listed['id']);
        $this->assertSame('My Manuscript', $listed['book_title']);
        $this->assertSame('Ada Obi', $listed['author_name']);
        $this->assertSame('ada@example.com', $listed['email']);
        $this->assertSame('Hardcover', $listed['details']['format']);
        $this->assertSame(['cover.jpg', 'manuscript.pdf'], array_column($listed['files'], 'name'));

        $this->patchJson("/api/applications/{$id}", ['status' => 'in_review', 'admin_notes' => 'Looks good'])->assertOk();
        $this->assertSame('in_review', Application::find($id)->status);
    }

    #[Test]
    public function applications_do_not_use_up_the_login_rate_limit(): void
    {
        $this->assertContains('throttle:5,60,applications', app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('/api/applications', 'POST')
        )->middleware());

        $this->postJson('/api/applications', [])->assertStatus(422);
        $this->postJson('/api/applications', [])->assertStatus(422);
        $this->postJson('/api/auth/login', [])->assertHeader('X-RateLimit-Remaining', '4');
    }
}
