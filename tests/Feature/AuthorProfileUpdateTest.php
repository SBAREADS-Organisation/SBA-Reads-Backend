<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();
    }

    /** What the app's Edit Profile screen sends. */
    private function appPayload(array $extra = []): array
    {
        return array_merge([
            'profile_info' => ['bio' => 'I write about AI.', 'pronouns' => '', 'username' => 'Ade Writer'],
            'preferences' => ['genres' => ['Technology', 'Faith']],
            'socials' => [
                ['platform' => 'linkedin', 'url' => 'https://linkedin.com/in/ade'],
                ['platform' => 'website', 'url' => 'https://breakingdownaibook.com'],
            ],
        ], $extra);
    }

    #[Test]
    public function an_author_can_save_their_profile_from_the_app(): void
    {
        $author = User::factory()->create(['account_type' => 'author']);
        Sanctum::actingAs($author);

        $this->post('/api/user/profile', $this->appPayload(), ['Accept' => 'application/json'])->assertOk();

        $author->refresh();
        $this->assertSame('Ade Writer', $author->username);
        $this->assertSame('https://breakingdownaibook.com', $author->profile_info['socials'][1]['url']);
    }

    #[Test]
    public function an_author_with_older_profile_data_can_save(): void
    {
        $author = User::factory()->create(['account_type' => 'author']);
        // Values some older accounts hold: JSON stored as text, and a plain-URL photo.
        \DB::table('users')->where('id', $author->id)->update([
            'profile_info' => json_encode(json_encode(['socials' => []])),
            'preferences' => json_encode('reader'),
            'settings' => null,
            'profile_picture' => json_encode('https://example.com/me.jpg'),
        ]);
        Sanctum::actingAs($author->fresh());

        $this->post('/api/user/profile', $this->appPayload(), ['Accept' => 'application/json'])->assertOk();
    }

    #[Test]
    public function an_author_can_save_with_a_new_photo(): void
    {
        $author = User::factory()->create(['account_type' => 'author']);
        Sanctum::actingAs($author);

        $this->post('/api/user/profile', $this->appPayload([
            'profile_picture' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ]), ['Accept' => 'application/json'])->assertOk();
    }
}
