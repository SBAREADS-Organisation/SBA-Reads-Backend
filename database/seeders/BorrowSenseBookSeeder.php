<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BorrowSenseBookSeeder extends Seeder
{
    public function run(): void
    {
        if (Book::where('slug', 'borrow-sense')->exists()) {
            $this->command->warn('Borrow Sense already exists — skipping.');
            return;
        }

        // Attach to the first superadmin/admin as the platform publisher
        $platform = User::role(['superadmin', 'admin'])->orderBy('id')->first();

        if (! $platform) {
            $this->command->error('No admin user found. Create an admin account first.');
            return;
        }

        $book = Book::create([
            'title'            => 'Borrow Sense',
            'sub_title'        => 'A Streetwise Guide to Wealth, Wisdom and Winning with Money',
            'slug'             => Str::slug('Borrow Sense'),
            'author_id'        => $platform->id,
            'description'      => 'Borrow Sense is a practical, streetwise guide to understanding money — how to earn it, manage it, and make it work for you. Written by Sola Adesakin, this book breaks down complex financial concepts into relatable, actionable wisdom for everyday people ready to take control of their finances.',
            'publisher'        => 'Sola Adesakin',
            'actual_price'     => 18.50,
            'discounted_price' => 14.80,
            'currency'         => 'USD',
            'status'           => 'approved',
            'visibility'       => 'public',
            'has_physical'     => true,
            'preorder_url'     => 'https://sbareads.com/product/borrow-sense/',
            'format'           => 'physical',
            'availability'     => ['physical'],
            'genres'           => ['Finance', 'Self-Help', 'Personal Development'],
            'tags'             => ['money', 'wealth', 'finance', 'financial literacy', 'personal development'],
            'language'         => ['English'],
            'target_audience'  => ['Adults', 'Young Adults'],
            'cover_image'      => ['public_url' => '', 'public_id' => ''],
            'files'            => [],
            'approved_at'      => now(),
        ]);

        // Add to book_authors pivot so it appears under the platform account
        $book->authors()->sync([$platform->id]);

        $this->command->info("✓ Borrow Sense created (ID: {$book->id})");
        $this->command->info("  Pre-order URL: {$book->preorder_url}");
        $this->command->warn("  Upload the cover image via Admin → Books → Borrow Sense to complete the listing.");
    }
}
