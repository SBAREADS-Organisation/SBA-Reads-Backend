<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemapBookIds extends Command
{
    protected $signature = 'books:remap-ids
                            {from* : Old book IDs to remap (space-separated)}
                            {--to=  : New IDs in matching order, comma-separated (e.g. 52,53,54)}
                            {--sequence=55 : Value to reset the books sequence to after remapping}
                            {--dry-run : Show what would change without executing}';

    protected $description = 'Remap book IDs and update every related table. Resets the auto-increment sequence.';

    // Every table that holds a book_id foreign key
    private const FK_TABLES = [
        'book_authors'                => 'book_id',
        'reading_progress'            => 'book_id',
        'book_reviews'                => 'book_id',
        'book_categories'             => 'book_id',
        'book_meta_data_analytics'    => 'book_id',
        'book_user_bookmarks'         => 'book_id',
        'order_items'                 => 'book_id',
        'book_audits'                 => 'book_id',
        'book_user'                   => 'book_id',
        'digital_book_purchase_items' => 'book_id',
        'book_chapters'               => 'book_id',
        'audio_book_purchases'        => 'book_id',
    ];

    public function handle(): int
    {
        $fromIds = array_map('intval', $this->argument('from'));
        $toRaw   = trim($this->option('to') ?? '');
        $seqNext = (int) ($this->option('sequence') ?? 55);
        $dryRun  = (bool) $this->option('dry-run');

        if (! $toRaw) {
            $this->error('--to is required.  Example: php artisan books:remap-ids 49 50 51 --to=52,53,54');
            return self::FAILURE;
        }

        $toIds = array_map('intval', explode(',', $toRaw));

        if (count($fromIds) !== count($toIds)) {
            $this->error(sprintf(
                'Count mismatch: %d source ID(s) but %d target ID(s).',
                count($fromIds),
                count($toIds)
            ));
            return self::FAILURE;
        }

        // Check source books exist
        foreach ($fromIds as $id) {
            if (! DB::table('books')->where('id', $id)->exists()) {
                $this->error("Source book ID {$id} not found in the database.");
                return self::FAILURE;
            }
        }

        // Check target IDs are free
        foreach ($toIds as $id) {
            if (DB::table('books')->where('id', $id)->exists()) {
                $this->error("Target book ID {$id} already exists — choose a free ID.");
                return self::FAILURE;
            }
        }

        $map = array_combine($fromIds, $toIds);

        // ── Preview table ──────────────────────────────────────────────────
        $this->line('');
        $this->line('<fg=cyan>Books to remap:</>');
        $rows = [];
        foreach ($map as $oldId => $newId) {
            $title = DB::table('books')->where('id', $oldId)->value('title') ?? '(unknown)';
            $rows[] = [$oldId, $newId, $title];
        }
        $this->table(['Old ID', 'New ID', 'Title'], $rows);

        $this->line('');
        $this->line('<fg=cyan>Child rows that will be re-pointed:</>');
        foreach ($map as $oldId => $newId) {
            $this->line("  Book {$oldId} → {$newId}");
            foreach (self::FK_TABLES as $table => $col) {
                $count = DB::table($table)->where($col, $oldId)->count();
                if ($count > 0) {
                    $this->line("    {$table}: {$count} row(s)");
                }
            }
            $mediaCount = DB::table('media_uploads')
                ->where('mediable_type', 'book')
                ->where('mediable_id', $oldId)
                ->count();
            if ($mediaCount > 0) {
                $this->line("    media_uploads: {$mediaCount} row(s)");
            }
        }
        $this->line("  Sequence will be reset → next new book gets ID {$seqNext}");

        if ($dryRun) {
            $this->line('');
            $this->warn('Dry run — no changes made.');
            return self::SUCCESS;
        }

        $this->line('');
        if (! $this->confirm('Proceed? This runs inside a transaction and can be rolled back if it fails.')) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        // ── Execute ────────────────────────────────────────────────────────
        DB::transaction(function () use ($map, $seqNext) {
            foreach ($map as $oldId => $newId) {
                // 1. Clone book row at new ID
                $book         = (array) DB::table('books')->where('id', $oldId)->first();
                $book['id']   = $newId;
                DB::table('books')->insert($book);

                // 2. Re-point every FK child table
                foreach (self::FK_TABLES as $table => $col) {
                    $updated = DB::table($table)->where($col, $oldId)->update([$col => $newId]);
                    if ($updated) {
                        $this->line("    Updated {$table}: {$updated} row(s)");
                    }
                }

                // 3. Re-point polymorphic media uploads
                $mediaUpdated = DB::table('media_uploads')
                    ->where('mediable_type', 'book')
                    ->where('mediable_id', $oldId)
                    ->update(['mediable_id' => $newId]);
                if ($mediaUpdated) {
                    $this->line("    Updated media_uploads: {$mediaUpdated} row(s)");
                }

                // 4. Delete the old book row
                //    Any child rows still pointing to $oldId (shouldn't be any) cascade-delete.
                DB::table('books')->where('id', $oldId)->delete();

                $this->info("  ✓ Book {$oldId} → {$newId}");
            }

            // 5. Reset PostgreSQL sequence so the next auto-assigned ID is $seqNext
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('books', 'id'), ?, false)",
                [$seqNext]
            );
        });

        $this->line('');
        $this->info("Done. Sequence reset — next new book will get ID {$seqNext}.");
        return self::SUCCESS;
    }
}
