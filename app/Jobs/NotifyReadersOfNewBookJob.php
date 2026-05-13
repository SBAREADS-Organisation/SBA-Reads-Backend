<?php

namespace App\Jobs;

use App\Models\Book;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotifyReadersOfNewBookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly Book $book) {}

    public function handle(): void
    {
        $book   = $this->book->loadMissing('authors');
        $author = $book->authors->first()?->name ?? 'an author';

        $title = 'New Book Available 📚';
        $body  = "'{$book->title}' by {$author} is now available on SBAReads!";
        $data  = [
            'type'       => 'new_book',
            'book_id'    => $book->id,
            'book_title' => $book->title,
        ];

        // Stream readers in chunks so we never load the whole table into memory.
        User::where('account_type', 'reader')
            ->select(['id', 'device_token'])
            ->chunkById(200, function ($readers) use ($title, $body, $data, $book) {
                $this->sendPushBatch($readers->pluck('device_token')->filter()->values()->all(), $title, $body, $data, $book);
                $this->createInAppNotifications($readers, $title, $body, $data, $book);
            });
    }

    /**
     * Send push notifications in batches of 100 — Expo's per-request limit.
     */
    private function sendPushBatch(array $tokens, string $title, string $body, array $data, Book $book): void
    {
        foreach (array_chunk($tokens, 100) as $batch) {
            $messages = array_map(fn ($token) => [
                'to'    => $token,
                'title' => $title,
                'body'  => $body,
                'data'  => $data,
                'sound' => 'default',
            ], $batch);

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])->post('https://exp.host/--/api/v2/push/send', $messages);

                Log::info('New-book push batch sent', [
                    'book_id' => $book->id,
                    'count'   => count($batch),
                    'status'  => $response->status(),
                ]);
            } catch (\Exception $e) {
                Log::error('New-book push batch failed', [
                    'book_id' => $book->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Bulk-insert in-app notifications for this chunk of readers.
     * One DB round-trip per chunk instead of one per reader.
     */
    private function createInAppNotifications($readers, string $title, string $body, array $data, Book $book): void
    {
        $now  = now();
        $rows = $readers->map(fn ($reader) => [
            'id'              => (string) Str::uuid(),
            'user_id'         => $reader->id,
            'title'           => $title,
            'message'         => $body,
            'channels'        => json_encode(['in-app', 'push']),
            'notifiable_type' => 'book',
            'notifiable_id'   => $book->id,
            'status'          => 'sent',
            'type'            => 'system',
            'data'            => json_encode($data),
            'read'            => false,
            'sent_at'         => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ])->all();

        Notification::insert($rows);
    }
}
