<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateBookPurchases extends Command
{
    protected $signature = 'books:migrate-purchases
                            {from : Old (broken) book ID}
                            {to   : New (working) book ID}
                            {--dry-run : Show what would change without touching the DB}';

    protected $description = 'Move all purchases and library entries from a broken book to its replacement';

    public function handle(): int
    {
        $fromId  = (int) $this->argument('from');
        $toId    = (int) $this->argument('to');
        $dryRun  = $this->option('dry-run');

        if ($fromId === $toId) {
            $this->error('From and To IDs must be different.');
            return 1;
        }

        $fromBook = DB::table('books')->where('id', $fromId)->first(['id', 'title']);
        $toBook   = DB::table('books')->where('id', $toId)->first(['id', 'title']);

        if (! $fromBook) { $this->error("Book {$fromId} not found."); return 1; }
        if (! $toBook)   { $this->error("Book {$toId} not found.");   return 1; }

        $this->newLine();
        $this->line("  FROM: [{$fromId}] {$fromBook->title}");
        $this->line("  TO:   [{$toId}] {$toBook->title}");
        $this->newLine();

        if ($dryRun) {
            $this->warn('  DRY RUN — no changes will be made.');
            $this->newLine();
        }

        // ── book_user (library) ───────────────────────────────────────
        $libraryRows = DB::table('book_user')->where('book_id', $fromId)->get(['user_id']);
        $this->line("  book_user (library entries): {$libraryRows->count()} row(s)");

        if (! $dryRun && $libraryRows->isNotEmpty()) {
            foreach ($libraryRows as $row) {
                // book_user has no unique constraint — check manually to avoid duplicates
                $alreadyThere = DB::table('book_user')
                    ->where('user_id', $row->user_id)
                    ->where('book_id', $toId)
                    ->exists();

                if (! $alreadyThere) {
                    DB::table('book_user')->insert([
                        'user_id'    => $row->user_id,
                        'book_id'    => $toId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::table('book_user')->where('book_id', $fromId)->delete();
            $this->line('    → migrated');
        }

        // ── digital_book_purchase_items ───────────────────────────────
        $itemRows = DB::table('digital_book_purchase_items')->where('book_id', $fromId)->count();
        $this->line("  digital_book_purchase_items: {$itemRows} row(s)");

        if (! $dryRun && $itemRows > 0) {
            DB::table('digital_book_purchase_items')
                ->where('book_id', $fromId)
                ->update(['book_id' => $toId]);
            $this->line('    → migrated');
        }

        // ── reading_progress ─────────────────────────────────────────
        $progressRows = DB::table('reading_progress')->where('book_id', $fromId)->count();
        $this->line("  reading_progress: {$progressRows} row(s)");

        if (! $dryRun && $progressRows > 0) {
            DB::table('reading_progress')
                ->where('book_id', $fromId)
                ->update(['book_id' => $toId]);
            $this->line('    → migrated');
        }

        // ── bookmarks / book_meta_data_analytics ─────────────────────
        foreach (['bookmarks', 'book_meta_data_analytics'] as $table) {
            if (! \Schema::hasTable($table)) continue;
            $count = DB::table($table)->where('book_id', $fromId)->count();
            $this->line("  {$table}: {$count} row(s)");
            if (! $dryRun && $count > 0) {
                DB::table($table)->where('book_id', $fromId)->update(['book_id' => $toId]);
                $this->line('    → migrated');
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('  Dry run complete. Re-run without --dry-run to apply.');
        } else {
            $this->info("  Done. All purchases from book {$fromId} are now on book {$toId}.");
            $this->line("  You can now archive or delete book {$fromId}.");
        }

        $this->newLine();
        return 0;
    }
}
