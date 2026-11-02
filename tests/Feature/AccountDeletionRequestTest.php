<?php

namespace Tests\Feature;

use App\Mail\Generic\AccountDeletionNotice;
use App\Models\AccountDeletionRequest;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    private function reader(): User
    {
        return User::factory()->create(['account_type' => 'reader']);
    }

    private function admin(): User
    {
        $admin = $this->reader();
        $admin->assignRole('admin');

        return $admin;
    }

    private function codeFor(User $user): string
    {
        $this->postJson('/api/account-deletion/request', ['email' => $user->email])->assertOk();

        $code = null;
        Mail::assertSent(AccountDeletionNotice::class, function ($mail) use ($user, &$code) {
            if ($mail->code && $mail->hasTo($user->email)) {
                $code = $mail->code;
            }

            return true;
        });

        return (string) $code;
    }

    private function confirm(User $user, string $code, array $extra = [])
    {
        return $this->postJson('/api/account-deletion/confirm', [
            'email' => $user->email,
            'otp' => $code,
            'acknowledged' => true,
            ...$extra,
        ]);
    }

    #[Test]
    public function a_verified_request_is_recorded_without_deleting_anything_yet(): void
    {
        $user = $this->reader();

        $this->confirm($user, $this->codeFor($user), ['reason' => 'Not using it'])->assertStatus(201);

        $this->assertDatabaseHas('account_deletion_requests', [
            'user_id' => $user->id, 'status' => 'pending', 'reason' => 'Not using it',
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Mail::assertSent(AccountDeletionNotice::class, fn ($mail) => $mail->hasTo(config('services.account_deletion.notify_email')));
    }

    #[Test]
    public function nobody_can_request_deletion_of_someone_elses_account_without_the_code(): void
    {
        $victim = $this->reader();
        $code = $this->codeFor($victim);
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->confirm($victim, $wrong)->assertStatus(400);
        $this->confirm($this->reader(), $code)->assertStatus(400);
        $this->assertSame(0, AccountDeletionRequest::count());

        for ($i = 0; $i < 4; $i++) {
            $this->confirm($victim, $wrong)->assertStatus(400);
        }
        // Locked after five wrong guesses, even with the right code.
        $this->confirm($victim, $code)->assertStatus(400);
        $this->assertSame(0, AccountDeletionRequest::count());
    }

    #[Test]
    public function the_response_does_not_reveal_whether_an_account_exists(): void
    {
        $known = $this->postJson('/api/account-deletion/request', ['email' => $this->reader()->email])->assertOk();
        $unknown = $this->postJson('/api/account-deletion/request', ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    #[Test]
    public function deletion_must_be_acknowledged_and_repeat_requests_do_not_duplicate(): void
    {
        $user = $this->reader();
        $this->confirm($user, $this->codeFor($user), ['acknowledged' => false])->assertStatus(422);

        $this->confirm($user, $this->codeFor($user))->assertStatus(201);
        $this->confirm($user, $this->codeFor($user))->assertOk();

        $this->assertSame(1, AccountDeletionRequest::count());
    }

    #[Test]
    public function staff_accounts_cannot_be_requested_through_the_public_form(): void
    {
        $this->postJson('/api/account-deletion/request', ['email' => User::factory()->create(['account_type' => 'superadmin'])->email])->assertOk();
        $this->postJson('/api/account-deletion/request', ['email' => $this->admin()->email])->assertOk();

        Mail::assertNothingSent();
    }

    #[Test]
    public function staff_complete_a_reader_request_and_the_financial_records_remain(): void
    {
        $user = $this->reader();
        $user->createToken('session');
        $book = Book::factory()->create(['status' => 'approved']);
        DB::table('book_user')->insert(['user_id' => $user->id, 'book_id' => $book->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->confirm($user, $this->codeFor($user))->assertStatus(201);
        $request = AccountDeletionRequest::first();

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->postJson("/api/account-deletion-requests/{$request->id}/complete")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('book_user', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('books', ['id' => $book->id]);
        $this->assertDatabaseHas('account_deletion_requests', [
            'id' => $request->id, 'status' => 'completed', 'processed_by' => $admin->id,
        ]);

        $this->postJson("/api/account-deletion-requests/{$request->id}/complete")->assertStatus(409);
    }

    #[Test]
    public function author_requests_are_never_deleted_automatically_so_buyers_keep_their_books(): void
    {
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create(['author_id' => $author->id, 'status' => 'approved']);
        $buyer = $this->reader();
        DB::table('book_user')->insert(['user_id' => $buyer->id, 'book_id' => $book->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->confirm($author, $this->codeFor($author))->assertStatus(201);
        $request = AccountDeletionRequest::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/account-deletion-requests/{$request->id}/complete")->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $author->id]);
        $this->assertDatabaseHas('book_user', ['user_id' => $buyer->id, 'book_id' => $book->id]);
        $this->assertSame('pending', $request->fresh()->status);

        $this->patchJson("/api/account-deletion-requests/{$request->id}", ['status' => 'completed', 'admin_notes' => 'Handled by hand'])->assertOk();
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertDatabaseHas('users', ['id' => $author->id]);
    }

    #[Test]
    public function an_email_with_reader_and_author_accounts_is_never_deleted_automatically(): void
    {
        $reader = $this->reader();
        $author = User::factory()->create(['account_type' => 'author', 'email' => $reader->email]);

        $this->confirm($reader, $this->codeFor($reader))->assertStatus(201);
        $request = AccountDeletionRequest::first();

        $this->assertSame($author->id, $request->user_id);
        $this->assertStringContainsString('more than one account', $request->admin_notes);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/account-deletion-requests/{$request->id}/complete")->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $reader->id]);
        $this->assertDatabaseHas('users', ['id' => $author->id]);
    }

    #[Test]
    public function only_staff_can_see_or_process_requests(): void
    {
        $user = $this->reader();
        $this->confirm($user, $this->codeFor($user))->assertStatus(201);
        $request = AccountDeletionRequest::first();

        $this->getJson('/api/account-deletion-requests')->assertStatus(401);
        Sanctum::actingAs($this->reader());
        $this->getJson('/api/account-deletion-requests')->assertStatus(403);
        $this->postJson("/api/account-deletion-requests/{$request->id}/complete")->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/account-deletion-requests')->assertOk()->assertJsonPath('data.data.0.id', $request->id);
    }
}
