<?php

namespace App\Console\Commands;

use App\Models\Book;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class UploadBookCoverFromUrl extends Command
{
    protected $signature = 'book:upload-cover {slug} {url} {--force}';

    protected $description = 'Upload a cover image from a URL to Cloudinary and attach it to a book';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $url  = $this->argument('url');

        $book = Book::where('slug', $slug)->first();

        if (! $book) {
            $this->error("Book not found: {$slug}");
            return 1;
        }

        // Skip if cover already uploaded
        $existing = $book->cover_image;
        if (is_array($existing) && ! empty($existing['public_url'])) {
            $this->warn("Cover already set — skipping. Use --force to overwrite.");
            if (! $this->option('force')) return 0;
        }

        $this->info("Uploading cover for: {$book->title}");

        Configuration::instance([
            'cloud' => [
                'cloud_name' => config('services.cloud.cloud_name'),
                'api_key'    => config('services.cloud.api_key'),
                'api_secret' => config('services.cloud.api_secret'),
            ],
            'url' => ['secure' => true],
        ]);

        $folder   = 'books/covers/' . now()->format('Y/m/d');
        $publicId = Str::uuid()->toString();

        $result = (new UploadApi)->upload($url, [
            'folder'        => $folder,
            'public_id'     => $publicId,
            'resource_type' => 'image',
            'fetch_format'  => 'auto',
            'quality'       => 'auto',
        ]);

        $book->update([
            'cover_image' => [
                'public_url' => $result['secure_url'],
                'public_id'  => $result['public_id'],
            ],
        ]);

        $this->info("✓ Cover uploaded and saved.");
        $this->line("  URL: {$result['secure_url']}");

        return 0;
    }
}
