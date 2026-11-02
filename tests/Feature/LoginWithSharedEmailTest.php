<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginWithSharedEmailTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'both@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();

        User::factory()->create(['email' => self::EMAIL, 'account_type' => 'reader', 'default_login' => 'email', 'password' => Hash::make('Reader-Pass1!')]);
        User::factory()->create(['email' => self::EMAIL, 'account_type' => 'author', 'default_login' => 'email', 'password' => Hash::make('Author-Pass1!')]);
    }

    private function login(string $password, array $extra = [])
    {
        return $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => $password, ...$extra]);
    }

    private function accountTypeFor($response): string
    {
        $session = Cache::get('login_session:' . $response->json('session_token'));

        return User::findOrFail($session['user_id'])->account_type;
    }

    #[Test]
    public function either_accounts_password_works_without_an_account_type(): void
    {
        $reader = $this->login('Reader-Pass1!')->assertOk()->assertJsonPath('email_otp_required', true);
        $author = $this->login('Author-Pass1!')->assertOk()->assertJsonPath('email_otp_required', true);

        $this->assertSame('reader', $this->accountTypeFor($reader));
        $this->assertSame('author', $this->accountTypeFor($author));
    }

    #[Test]
    public function a_wrong_password_is_still_refused(): void
    {
        $this->login('Wrong-Pass1!')->assertStatus(400)->assertJsonPath('error', 'Invalid credentials');
    }

    #[Test]
    public function a_requested_account_type_only_accepts_that_accounts_password(): void
    {
        $this->login('Author-Pass1!', ['account_type' => 'reader'])->assertStatus(400);

        $response = $this->login('Author-Pass1!', ['account_type' => 'author'])->assertOk();
        $this->assertSame('author', $this->accountTypeFor($response));
    }
}
