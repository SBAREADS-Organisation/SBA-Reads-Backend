<?php

namespace App\Console\Commands;

use App\Mail\Book\BookUploadFailureMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class NotifyBookUploadFailure extends Command
{
    protected $signature = 'books:notify-upload-failure
                            {ids* : Book IDs to notify authors about}
                            {--to= : Override recipient — sends all emails to this address instead (for testing)}
                            {--dry-run : Preview who would be emailed without sending}';

    protected $description = 'Email authors whose book files failed to upload, asking them to re-upload via book edit';

    public function handle(): int
    {
        $ids      = array_map('intval', $this->argument('ids'));
        $dryRun   = $this->option('dry-run');
        $testTo   = $this->option('to');

        // Load each affected book with its primary author
        $books = DB::table('books')
            ->join('users', 'users.id', '=', 'books.author_id')
            ->whereIn('books.id', $ids)
            ->get(['books.id', 'books.title', 'books.author_id', 'users.name as author_name', 'users.email as author_email']);

        if ($books->isEmpty()) {
            $this->error('No books found for the given IDs.');
            return 1;
        }

        // Group books by author so one email covers all their affected titles
        $byAuthor = $books->groupBy('author_id');

        $this->newLine();
        $this->line('  <fg=yellow>SBA Reads — Book Upload Failure Notifications</>');
        $this->line('  ' . str_repeat('─', 50));
        $this->newLine();

        if ($testTo) {
            $this->warn("  TEST MODE — all emails will be sent to: {$testTo}");
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('  DRY RUN — no emails will be sent.');
            $this->newLine();
        }

        $sent = 0;

        foreach ($byAuthor as $authorId => $authorBooks) {
            $rawName     = $authorBooks->first()->author_name ?? '';
            $authorName  = ($rawName && strtoupper(trim($rawName)) !== 'NO NAME') ? $rawName : 'Author';
            $authorEmail = $authorBooks->first()->author_email;
            $titles      = array_values(array_unique($authorBooks->pluck('title')->toArray()));

            $this->line("  Author : {$authorName} <{$authorEmail}>");
            $this->line("  Books  : " . implode(', ', $titles));

            if (! $dryRun) {
                try {
                    $recipient      = $testTo ?? $authorEmail;
                    $recipientName  = $testTo ? 'Test' : $authorName;
                    Mail::to($recipient, $recipientName)
                        ->send(new BookUploadFailureMail($authorName, $titles));
                    $this->info("  Status : Email sent");
                    $sent++;
                } catch (\Throwable $e) {
                    $this->error("  Status : FAILED — {$e->getMessage()}");
                }
            } else {
                $this->line("  Status : <fg=cyan>Would send email</>");
            }

            $this->newLine();
        }

        if (! $dryRun) {
            $this->info("  Done. {$sent} of {$byAuthor->count()} email(s) sent.");
        } else {
            $this->warn("  Dry run complete. Re-run without --dry-run to send.");
        }

        $this->newLine();
        return 0;
    }
}
