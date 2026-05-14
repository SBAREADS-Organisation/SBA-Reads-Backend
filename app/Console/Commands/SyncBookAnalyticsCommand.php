<?php

namespace App\Console\Commands;

use App\Models\BookMetaDataAnalytics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncBookAnalyticsCommand extends Command
{
    protected $signature   = 'books:sync-analytics {--book= : Only sync a single book by ID}';
    protected $description = 'Sync book_meta_data_analytics from source tables (purchases, views, bookmarks, reads)';

    public function handle(): int
    {
        $singleBookId = $this->option('book');

        $query = DB::table('books')
            ->where('status', 'approved')
            ->where('visibility', 'public')
            ->whereNull('deleted_at')
            ->select('id', 'views_count');

        if ($singleBookId) {
            $query->where('id', $singleBookId);
        }

        $total   = $query->count();
        $synced  = 0;

        $this->info("Syncing analytics for {$total} book(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunk(200, function ($books) use (&$synced, $bar) {
            $bookIds = $books->pluck('id')->all();

            // --- Purchases: users who own the book (book_user pivot) ---
            $purchases = DB::table('book_user')
                ->whereIn('book_id', $bookIds)
                ->selectRaw('book_id, COUNT(*) as cnt')
                ->groupBy('book_id')
                ->pluck('cnt', 'book_id');

            // --- Bookmarks ---
            $bookmarks = DB::table('book_user_bookmarks')
                ->whereIn('book_id', $bookIds)
                ->selectRaw('book_id, COUNT(*) as cnt')
                ->groupBy('book_id')
                ->pluck('cnt', 'book_id');

            // --- Reads: users with any reading progress on the book ---
            $reads = DB::table('reading_progresses')
                ->whereIn('book_id', $bookIds)
                ->selectRaw('book_id, COUNT(DISTINCT user_id) as cnt')
                ->groupBy('book_id')
                ->pluck('cnt', 'book_id');

            // --- Reviews count ---
            $reviews = DB::table('book_reviews')
                ->whereIn('book_id', $bookIds)
                ->selectRaw('book_id, COUNT(*) as cnt')
                ->groupBy('book_id')
                ->pluck('cnt', 'book_id');

            $now  = now();
            $rows = [];

            foreach ($books as $book) {
                $rows[] = [
                    'book_id'    => $book->id,
                    'views'      => $book->views_count ?? 0,
                    'purchases'  => $purchases[$book->id] ?? 0,
                    'bookmarks'  => $bookmarks[$book->id] ?? 0,
                    'reads'      => $reads[$book->id] ?? 0,
                    'likes'      => $reviews[$book->id] ?? 0,
                    'downloads'  => 0,
                    'favourites' => 0,
                    'shares'     => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Upsert — insert or update, never delete existing rows
            BookMetaDataAnalytics::upsert(
                $rows,
                ['book_id'],                                              // unique key
                ['views', 'purchases', 'bookmarks', 'reads', 'likes', 'updated_at'] // columns to update
            );

            $synced += count($rows);
            $bar->advance(count($rows));
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Synced {$synced} book(s).");

        return Command::SUCCESS;
    }
}
