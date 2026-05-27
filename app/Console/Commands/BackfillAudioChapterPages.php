<?php

namespace App\Console\Commands;

use App\Jobs\BackfillAudioChapterPagesJob;
use App\Models\Book;
use Illuminate\Console\Command;

class BackfillAudioChapterPages extends Command
{
    protected $signature = 'audio:backfill-pages
                            {--book= : Backfill a single book by ID}
                            {--all   : Backfill every book with null chapter pages}
                            {--force : Re-run even if pages are already populated}
                            {--sync  : Run synchronously (wait for each book instead of queuing)}';

    protected $description = 'Populate audio_chapters[N].page for books where it is missing';

    public function handle(): int
    {
        if ($this->option('book')) {
            $books = Book::where('id', $this->option('book'))
                ->whereNotNull('audio_chapters')
                ->whereNotNull('audio_segments')
                ->get();
        } elseif ($this->option('all')) {
            $books = Book::whereNotNull('audio_chapters')
                ->whereJsonLength('audio_chapters', '>', 0)
                ->whereNotNull('audio_segments')
                ->get()
                ->filter(function (Book $book) {
                    if ($this->option('force')) return true;
                    return collect($book->audio_chapters ?? [])->contains(fn ($ch) => ($ch['page'] ?? null) === null);
                });
        } else {
            $this->error('Pass --book=ID or --all');
            return self::FAILURE;
        }

        if ($books->isEmpty()) {
            $this->info('No books need backfilling.');
            return self::SUCCESS;
        }

        $this->info("Processing {$books->count()} book(s)…");
        $bar  = $this->output->createProgressBar($books->count());
        $sync = $this->option('sync');

        foreach ($books as $book) {
            if ($sync) {
                (new BackfillAudioChapterPagesJob($book))->handle();
                $this->line(" ✓ Book {$book->id} \"{$book->title}\"");
            } else {
                BackfillAudioChapterPagesJob::dispatch($book)->onQueue('audio');
            }
            $bar->advance();
        }

        $bar->finish();
        $this->line('');

        if ($sync) {
            $this->info('All books processed synchronously.');
        } else {
            $this->info("Queued {$books->count()} job(s) on the [audio] queue.");
        }

        return self::SUCCESS;
    }
}
