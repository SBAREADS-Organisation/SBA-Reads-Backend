<?php

namespace App\Services\Book;

use App\Models\Book;
use App\Models\DigitalBookPurchase;
use App\Models\DigitalBookPurchaseItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookService
{
    use ApiResponse;

    private PaymentService $paymentService;

    public function __construct(protected CloudinaryMediaUploadService $cloudinaryMediaService, PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Create multiple books in a transaction.
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
        if (! empty($data['pricing'])) {
            $data['actual_price'] = $data['pricing']['actual_price'] ?? null;
            $data['discounted_price'] = $data['pricing']['discounted_price'] ?? null;
        }

        // Upload cover image.
        if (isset($data['cover_image']) && $data['cover_image'] instanceof UploadedFile) {
            $upload = $this->cloudinaryMediaService->upload($data['cover_image'], 'book_cover');

            if ($upload instanceof JsonResponse) {
                $errorData = $upload->getData(true);
                throw new \Exception('Failed to upload cover image: ' . ($errorData['error'] ?? 'Unknown error'));
            }

            $data['cover_image'] = [
                'public_url' => (string) $upload['url'],
                'public_id' => $upload['public_id'],
            ];

            $mediaUploadIds[] = $upload['id'];
        }

        // Upload each file in 'files' array — PDFs go to S3 to avoid Cloudinary's 10 MB image cap
        if (! empty($data['files']) && is_array($data['files'])) {
            $uploadedFiles = [];
            foreach ($data['files'] as $file) {
                if ($file instanceof UploadedFile) {
                    $ext      = $file->getClientOriginalExtension() ?: 'pdf';
                    $fileName = Str::uuid() . '.' . $ext;
                    $s3Dir    = 'books/content/' . date('Y/m/d');
                    $s3Key    = $s3Dir . '/' . $fileName;

                    // Stream directly to S3 without setting a public ACL.
                    // Modern S3 buckets use "Bucket owner enforced" ownership which
                    // rejects ACL headers entirely — files are served via signed URLs instead.
                    $uploaded = Storage::disk('s3')->putFileAs($s3Dir, $file, $fileName);

                    if (! $uploaded) {
                        throw new \RuntimeException("Failed to upload book file to S3: {$fileName}");
                    }

                    // Store the canonical (non-signed) S3 key URL; signed URLs are
                    // generated at serve time in BookResource so they never expire in the DB.
                    $s3Url = Storage::disk('s3')->url($s3Key);

                    $media = \App\Models\MediaUpload::create([
                        'context'       => 'book_content',
                        'type'          => 'raw',
                        'folder'        => 'books/content/' . date('Y/m/d'),
                        'public_id'     => $s3Key,
                        'url'           => $s3Url,
                        'watermarked'   => false,
                        'is_temporary'  => false,
                        'mediable_type' => null,
                        'mediable_id'   => null,
                    ]);

                    $uploadedFiles[]   = [
                        'public_url' => $s3Url,
                        'public_id'  => $s3Key,
                    ];

                    $mediaUploadIds[] = $media->id;
                }
            }
            $data['files'] = $uploadedFiles;
        }

        // Only keep fillable attributes
        $attrs = Arr::only($data, (new Book)->getFillable());

        // Create the book
        $book = Book::create($attrs);

        // Sync authors pivot
        if (! empty($data['authors'] ?? null)) {
            $book->authors()->sync($data['authors']);
        }

        // Sync categories pivot
        if (! empty($data['categories'] ?? null)) {
            $book->categories()->sync($data['categories']);
        }

        // 4. Re-attach uploaded media with mediable_type/id
        \App\Models\MediaUpload::whereIn('id', $mediaUploadIds)->update([
            'mediable_type' => 'book',
            'mediable_id' => $book->id,
        ]);

        $book->update([
            'product_id'       => "sbareads.book.{$book->id}",
            'audio_product_id' => "sbareads.audio.{$book->id}",
        ]);

        return $book;
    }

    public function deleteBook(Book $book, string $reason)
    {
        // Check if book has purchases
        $hasPurchases = DigitalBookPurchase::whereHas('items', function ($q) use ($book) {
            $q->where('book_id', $book->id);
        })->where('status', 'completed')->exists();

        if ($hasPurchases) {
            throw new \Exception('Cannot delete book that has been purchased');
        }

        // Delete files from Cloudinary
        if ($book->cover_image && is_array($book->cover_image) && isset($book->cover_image['public_id'])) {
            $this->cloudinaryMediaService->delete($book->cover_image['public_id']);
        }

        // Delete book content files — check public_url domain to tell S3 from Cloudinary
        // (both use books/content/ as the path prefix so checking public_id alone is unreliable)
        if ($book->files && is_array($book->files)) {
            foreach ($book->files as $file) {
                if (! isset($file['public_id'])) {
                    continue;
                }
                $url = $file['public_url'] ?? $file['url'] ?? '';
                if (str_contains($url, 'amazonaws.com')) {
                    Storage::disk('s3')->delete($file['public_id']);
                } else {
                    $this->cloudinaryMediaService->delete($file['public_id']);
                }
            }
        }

        $book->delete();

        return true;
    }

    public function purchaseBooks(array $bookIds, User $user, string $paymentProvider = 'stripe')
    {
        // Create a digital book purchase
        $purchase = DigitalBookPurchase::create([
            'user_id' => $user->id,
            'total_amount' => 0,
            'currency' => $paymentProvider === 'paystack' ? 'NGN' : 'USD',
            'status' => 'pending',
            'payment_provider' => $paymentProvider,
        ]);

        $total = 0;
        foreach ($bookIds as $bookId) {
            $book = Book::findOrFail($bookId);
            $authorPayoutAmount = $book->pricing['actual_price'] * 0.7;
            $purchaseItem = DigitalBookPurchaseItem::create([
                'digital_book_purchase_id' => $purchase->id,
                'book_id' => $book->id,
                'author_id' => $book->authors->first()->id,
                'quantity' => 1,
                'price_at_purchase' => $book->pricing['actual_price'],
                'author_payout_amount' => $authorPayoutAmount,
                'platform_fee_amount' => $book->pricing['actual_price'] - $authorPayoutAmount,
                'payout_status' => 'pending',
                'payment_provider' => $paymentProvider,
                'provider_transfer_id' => null,
            ]);

            $total += $purchaseItem->price_at_purchase;
        }

        // Update the total amount in the purchase
        $purchase->update(['total_amount' => $total]);

        $transaction = $this->paymentService->createPayment([
            'amount' => $total,
            'currency' => $paymentProvider === 'paystack' ? 'NGN' : 'USD',
            'description' => 'Digital books purchase',
            'purpose' => 'digital_book_purchase',
            'purpose_id' => $purchase->id,
            'payment_provider' => $paymentProvider,
            'meta_data' => [
                'book_ids' => $bookIds,
                'user_id' => $user->id,
                'purchase_id' => $purchase->id,
                'payment_provider' => $paymentProvider,
            ],
        ], $user);

        // DO NOT add books to purchased list here - wait for successful payment
        // DO NOT update author wallets here - wait for successful payment

        if ($transaction instanceof JsonResponse) {
            $responseData = $transaction->getData(true);

            return $this->error(
                'An error occurred while initiating the books purchase process.',
                $transaction->getStatusCode(),
                $responseData['error'] ?? 'Unknown error from payment service.'
            );
        }

        return $this->success([
            'purchase' => $purchase->load('items.book'),
            'transaction' => $transaction,
            'client_secret' => $transaction->payment_client_secret,
        ], 'Purchase initiated successfully. Complete payment to access books.');
    }
}
