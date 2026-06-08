<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryFailedAudioJobs extends Command
{
    protected $signature = 'audio:retry-failed
                            {--dry-run : Show what would be reset without making changes}';

    protected $description = 'Reset all failed audio books to "none" and clear their failed queue jobs so authors can retry. Run this after restoring ElevenLabs credits.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Find all books stuck in failed or processing audio state
        $books = Book::whereIn('audio_status', ['failed', 'processing'])
            ->select('id', 'title', 'audio_status')
            ->get();

        if ($books->isEmpty()) {
            $this->info('No failed or stuck audio books found.');
            return 0;
        }

        $this->table(
            ['ID', 'Title', 'Status'],
            $books->map(fn ($b) => [$b->id, $b->title, $b->audio_status])
        );

        // Count failed audio jobs in the failed_jobs table
        $failedJobCount = DB::table('failed_jobs')
            ->where(function ($q) {
                $q->where('payload', 'like', '%GenerateAudioChunkJob%')
                  ->orWhere('payload', 'like', '%GenerateBookAudioJob%')
                  ->orWhere('payload', 'like', '%FinalizeBookAudioJob%');
            })
            ->count();

        $this->info("Books to reset: {$books->count()}");
        $this->info("Failed audio jobs to clear: {$failedJobCount}");

        if ($dryRun) {
            $this->warn('Dry run — no changes made. Remove --dry-run to apply.');
            return 0;
        }

        if (! $this->confirm('Reset all failed audio books and clear their failed jobs?', true)) {
            $this->info('Aborted.');
            return 0;
        }

        // Reset all failed/stuck books to 'none' so authors can retry
        $updated = Book::whereIn('audio_status', ['failed', 'processing'])
            ->update(['audio_status' => 'none']);

        // Clear all failed audio-related jobs from the failed_jobs table
        $deleted = DB::table('failed_jobs')
            ->where(function ($q) {
                $q->where('payload', 'like', '%GenerateAudioChunkJob%')
                  ->orWhere('payload', 'like', '%GenerateBookAudioJob%')
                  ->orWhere('payload', 'like', '%FinalizeBookAudioJob%');
            })
            ->delete();

        $this->info("✓ Reset {$updated} book(s) to audio_status = 'none'");
        $this->info("✓ Cleared {$deleted} failed audio job(s) from the queue");
        $this->info('Authors can now retry audio generation from the app.');

        return 0;
    }
}
