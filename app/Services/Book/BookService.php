<?php

namespace App\Services\Book;

use App\Models\Book;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Http\UploadedFile;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use Illuminate\Support\Facades\DB;

class BookService
{
    public function __construct(protected CloudinaryMediaUploadService $cloudinaryMediaService) {}
    /**
     * Create multiple books in a transaction.
     *
     * @param array $booksData
     * @return array
     */
    public function createBooks(array $booksData): array
    {
        $createdBooks = [];

        DB::transaction(function () use ($booksData, &$createdBooks) {
            foreach ($booksData as $data) {
                // Extract relationships
                $categories = $data['categories'];
                $authors = $data['authors'];

                // Remove relationships from the main data
                unset($data['categories'], $data['authors']);

                // Handle JSON fields
                if (isset($data['pricing']) && is_array($data['pricing'])) {
                    $data['pricing'] = json_encode($data['pricing']);
                }

                if (isset($data['cover_image']) && is_array($data['cover_image'])) {
                    $data['cover_image'] = json_encode($data['cover_image']);
                }

                // Create the book
                $book = Book::create($data);

                // Attach relationships
                $book->categories()->sync($categories);
                $book->authors()->sync($authors);

                $createdBooks[] = $book;
            }
        });

        return $createdBooks;
    }

    /**
    * Create multiple books in a transaction.
    */
    public function createMultiple(array $booksData)
    {
        return DB::transaction(function () use ($booksData) {
            return collect($booksData)
                ->map(fn($data) => $this->createSingle($data))
                ->all();
        });
    }

    /**
     * Create a single book, generate slug, and return the model.
     */
    protected function createSingle(array $data): Book
    {
        // Slug generation (ensure uniqueness)
        $slugBase = Str::slug($data['title']);
        $count = Book::where('slug', 'like', "{$slugBase}%")->count();
        $data['slug'] = $count ? "{$slugBase}-{$count}" : $slugBase;
        $mediaUploadIds = [];

        // Merge pricing fields (if any)
        if (!empty($data['pricing'])) {
            $data['actual_price']     = $data['pricing']['actual_price'] ?? null;
            $data['discounted_price'] = $data['pricing']['discounted_price'] ?? null;
        }

        // Upload cover image.
        if (isset($data['cover_image']) && $data['cover_image'] instanceof UploadedFile) {
            $upload = $this->cloudinaryMediaService->upload($data['cover_image'], 'book_cover');
            $data['cover_image'] = [
                'public_url' => $upload['url'],
                'public_id' => $upload['id'],
            ];

            $mediaUploadIds[] = $upload['id'];
        }

        // Upload each file in 'files' array
        if (!empty($data['files']) && is_array($data['files'])) {
            $uploadedFiles = [];
            foreach ($data['files'] as $file) {
                if ($file instanceof UploadedFile) {
                    $upload = $this->cloudinaryMediaService->upload($file, 'book_content');
                    $uploadedFiles[] = [
                        'public_url' => $upload['url'],
                        'public_id' => $upload['id'],
                    ];

                    $mediaUploadIds[] = $upload['id'];
                }
            }
            $data['files'] = $uploadedFiles;
        }

        // Only keep fillable attributes
        $attrs = Arr::only($data, (new Book)->getFillable());

        // Create the book
        $book = Book::create($attrs);

        // Sync authors pivot
        if (!empty($data['authors'] ?? null)) {
            $book->authors()->sync($data['authors']);
        }

        // Sync categories pivot
        if (!empty($data['categories'] ?? null)) {
            $book->categories()->sync($data['categories']);
        }

        // 4. Re-attach uploaded media with mediable_type/id
        \App\Models\MediaUpload::whereIn('id', $mediaUploadIds)->update([
            'mediable_type' => 'book',
            'mediable_id' => $book->id,
        ]);

        return $book;
    }
}
