<?php

namespace Tests\Feature;

use App\Models\AudioBookPurchase;
use App\Models\Book;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Services\Book\BookPurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReadingStateSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'superadmin', 'manager', 'author', 'user'] as $role) {
            Role::findOrCreate($role, 'api');
        }
        Mail::fake();
        Queue::fake();

        $this->reader = User::factory()->create(['account_type' => 'reader']);
        $author = User::factory()->create(['account_type' => 'author']);
        $this->book = Book::factory()->create(['author_id' => $author->id, 'meta_data' => ['pages' => 200]]);
        Sanctum::actingAs($this->reader);
    }

    private function ownBook(): void
    {
        app(BookPurchaseService::class)->addBooksToUserLibrary($this->reader, [$this->book->id]);
    }

    private function url(): string
    {
        return "/api/books/{$this->book->id}/reading-state";
    }

    #[Test]
    public function reading_state_is_private_to_people_with_access(): void
    {
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), ['page' => 5])->assertForbidden();
    }

    #[Test]
    public function a_new_reader_has_an_empty_state(): void
    {
        $this->ownBook();

        $this->getJson($this->url())->assertOk()->assertJsonPath('data', [
            'page' => null, 'bookmarks' => null, 'notes' => null, 'audio_position' => null,
        ]);
    }

    #[Test]
    public function what_one_device_saves_the_other_can_read(): void
    {
        $this->ownBook();

        $this->postJson($this->url(), [
            'page' => 50,
            'bookmarks' => [3, 9, 9],
            'notes' => ['3' => 'Remember this'],
            'audio_position' => ['segment_index' => 2, 'position_ms' => 91000],
        ])->assertOk();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.page', 50)
            ->assertJsonPath('data.bookmarks', [3, 9])
            ->assertJsonPath('data.notes.3', 'Remember this')
            ->assertJsonPath('data.audio_position.segment_index', 2)
            ->assertJsonPath('data.audio_position.position_ms', 91000);

        $this->assertSame(25, (int) ReadingProgress::where('book_id', $this->book->id)->first()->progress);
    }

    #[Test]
    public function saving_one_field_leaves_the_others_alone(): void
    {
        $this->ownBook();
        $this->postJson($this->url(), ['bookmarks' => [3], 'notes' => ['3' => 'Keep me'], 'page' => 10])->assertOk();

        $this->postJson($this->url(), ['page' => 11])->assertOk();

        $this->getJson($this->url())
            ->assertJsonPath('data.page', 11)
            ->assertJsonPath('data.bookmarks', [3])
            ->assertJsonPath('data.notes.3', 'Keep me');
    }

    #[Test]
    public function removing_the_last_bookmark_and_note_is_saved(): void
    {
        $this->ownBook();
        $this->postJson($this->url(), ['bookmarks' => [3], 'notes' => ['3' => 'Temporary']])->assertOk();

        $this->postJson($this->url(), ['bookmarks' => [], 'notes' => []])->assertOk();

        $response = $this->getJson($this->url())->assertJsonPath('data.bookmarks', []);
        $this->assertStringContainsString('"notes":{}', $response->getContent());
    }

    #[Test]
    public function released_app_versions_saving_a_page_cannot_wipe_synced_bookmarks_or_session_data(): void
    {
        $this->ownBook();
        $this->postJson($this->url(), ['bookmarks' => [3], 'notes' => ['3' => 'Keep me']])->assertOk();
        ReadingProgress::where('book_id', $this->book->id)->update(['session_duration' => json_encode([['minutes' => 5]])]);

        // What the current app sends: the page and its on-device bookmark list.
        $this->postJson("/api/books/{$this->book->id}/start-reading", ['page' => '40', 'bookmarks' => [8]])->assertOk();

        $this->getJson($this->url())
            ->assertJsonPath('data.page', 40)
            ->assertJsonPath('data.bookmarks', [3])
            ->assertJsonPath('data.notes.3', 'Keep me');
        $this->assertNotEmpty(ReadingProgress::where('book_id', $this->book->id)->first()->session_duration);
    }

    #[Test]
    public function bad_reading_data_is_rejected(): void
    {
        $this->ownBook();

        $this->postJson($this->url(), ['page' => 0])->assertStatus(422);
        $this->postJson($this->url(), ['bookmarks' => ['x']])->assertStatus(422);
        $this->postJson($this->url(), ['audio_position' => ['segment_index' => 1]])->assertStatus(422);
    }

    #[Test]
    public function audio_can_only_be_bought_after_the_book(): void
    {
        $this->book->update(['audio_url' => 'https://example.com/a.mp3', 'audio_status' => 'ready']);

        $this->postJson("/api/books/{$this->book->id}/purchase-audio", ['currency' => 'NGN'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Buy the book first to unlock its audio version.');
        $this->assertSame(0, AudioBookPurchase::count());
    }
}
