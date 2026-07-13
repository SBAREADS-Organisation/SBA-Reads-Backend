<?php

namespace App\Console\Commands;

use App\Mail\Book\BookUploadFailureMail;
use App\Mail\Book\BookUploadReminderMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class NotifyBookUploadFailure extends Command
{
    protected $signature = 'books:notify-upload-failure
                            {ids* : Book IDs to notify authors about}
                            {--to=      : Override recipient — sends all emails to this address instead (for testing)}
                            {--check    : Show S3 upload status for each book without sending any email}
                            {--only-missing : Only email authors whose books are still missing on S3}
                            {--remind   : Send a short reminder instead of the full failure notice}
                            {--dry-run  : Preview who would be emailed without sending}';

    protected $description = 'Email authors whose book files failed to upload, with status check and reminder support';

    public function handle(): int
    {
        $ids          = array_map('intval', $this->argument('ids'));
        $dryRun       = $this->option('dry-run');
        $testTo       = $this->option('to');
        $checkOnly    = $this->option('check');
        $onlyMissing  = $this->option('only-missing');
        $remind       = $this->option('remind');

        $books = DB::table('books')
            ->join('users', 'users.id', '=', 'books.author_id')
            ->whereIn('books.id', $ids)
            ->get(['books.id', 'books.title', 'books.files', 'books.author_id',
                   'users.name as author_name', 'users.email as author_email']);

        if ($books->isEmpty()) {
            $this->error('No books found for the given IDs.');
            return 1;
        }

        $this->newLine();
        $this->line('  <fg=yellow>SBA Reads — Book Upload Failure Notifications</>');
        $this->line('  ' . str_repeat('─', 50));
        $this->newLine();

        // ── S3 status check ───────────────────────────────────────────────
        $s3Status = [];
        foreach ($books as $book) {
            $files = is_string($book->files) ? json_decode($book->files, true) : (array) $book->files;
            $key   = $files[0]['public_id'] ?? null;
            $s3Status[$book->id] = $key && Storage::disk('s3')->exists($key);
        }

        $uploaded = array_keys(array_filter($s3Status));
        $missing  = array_keys(array_filter($s3Status, fn($v) => ! $v));

        // ── --check: print status table and exit ─────────────────────────
        if ($checkOnly) {
            $rows = $books->map(fn($b) => [
                $b->id,
                $b->title,
                $b->author_name ?: 'NO NAME',
                $s3Status[$b->id] ? '<fg=green>✓ Uploaded</>' : '<fg=red>✗ Missing</>',
            ])->toArray();

            $this->table(['ID', 'Title', 'Author', 'S3 Status'], $rows);
            $this->newLine();
            $this->info("  Uploaded : " . count($uploaded) . " book(s)");
            $this->warn("  Missing  : " . count($missing)  . " book(s)");
            $this->newLine();
            return 0;
        }

        // ── Filter to only missing if requested ───────────────────────────
        $targetIds = $onlyMissing ? $missing : $ids;

        if ($onlyMissing && empty($targetIds)) {
            $this->info('  All books have been re-uploaded. No reminders needed.');
            $this->newLine();
            return 0;
        }

        $filtered = $books->whereIn('id', $targetIds);

        if ($testTo) {
            $this->warn("  TEST MODE — all emails will be sent to: {$testTo}");
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('  DRY RUN — no emails will be sent.');
            $this->newLine();
        }

        if ($onlyMissing) {
            $this->warn("  MISSING ONLY — skipping " . count($uploaded) . " already re-uploaded book(s).");
            $this->newLine();
        }

        // Group by author
        $byAuthor = $filtered->groupBy('author_id');
        $sent = 0;

        foreach ($byAuthor as $authorId => $authorBooks) {
            $rawName     = $authorBooks->first()->author_name ?? '';
            $authorName  = ($rawName && strtoupper(trim($rawName)) !== 'NO NAME') ? $rawName : 'Author';
            $authorEmail = $authorBooks->first()->author_email;
            $titles      = array_values(array_unique($authorBooks->pluck('title')->toArray()));

            // Annotate which of their titles are still missing
            $stillMissing = $authorBooks
                ->filter(fn($b) => ! ($s3Status[$b->id] ?? false))
                ->pluck('title')
                ->unique()
                ->values()
                ->toArray();

            $this->line("  Author : {$authorName} <{$authorEmail}>");
            $this->line("  Books  : " . implode(', ', $titles));

            if ($onlyMissing || $remind) {
                $label = empty($stillMissing) ? '<fg=green>all uploaded</>' : '<fg=red>still missing: ' . implode(', ', $stillMissing) . '</>';
                $this->line("  S3     : {$label}");
            }

            if (! $dryRun) {
                try {
                    $recipient     = $testTo ?? $authorEmail;
                    $recipientName = $testTo ? 'Test' : $authorName;

                    $mailable = $remind
                        ? new BookUploadReminderMail($authorName, $titles)
                        : new BookUploadFailureMail($authorName, $titles);

                    Mail::to($recipient, $recipientName)->send($mailable);
                    $this->info("  Status : Email sent");
                    $sent++;
                } catch (\Throwable $e) {
                    $this->error("  Status : FAILED — {$e->getMessage()}");
                }
            } else {
                $type = $remind ? 'reminder' : 'failure notice';
                $this->line("  Status : <fg=cyan>Would send {$type}</>");
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
