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
            $data['cover_image'] = [
                'public_url' => $upload['url'],
                'public_id' => $upload['id'],
            ];

            $mediaUploadIds[] = $upload['id'];
        }

        // Upload each file in 'files' array
        if (! empty($data['files']) && is_array($data['files'])) {
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

        return $book;
    }

    public function deleteBook(Book $book, string $reason)
    {
        $book->delete();

        return true;
    }

    public function purchaseBooks(array $bookIds, User $user)
    {

        // Create a digital book purchase
        $purchase = DigitalBookPurchase::create(
            [
                'user_id' => $user->id,
                'total_amount' => 0, // This will be updated after calculating total
                'currency' => 'usd',
                'status' => 'pending',
            ]
        );
        $total = 0;
        foreach ($bookIds as $bookId) {
            $book = Book::findOrFail($bookId);
            $authorPayoutAmount = $book->pricing['actual_price'] * 0.7;
            $purchaseItem = DigitalBookPurchaseItem::create(
                [
                    'digital_book_purchase_id' => $purchase->id,
                    'book_id' => $book->id,
                    'author_id' => $book->authors->first()->id,
                    'quantity' => 1, // Assuming quantity is always 1 for digital books
                    'price_at_purchase' => $book->pricing['actual_price'],
                    'author_payout_amount' => $authorPayoutAmount,
                    'platform_fee_amount' => $book->pricing['actual_price'] - $authorPayoutAmount,
                    'payout_status' => 'pending',
                    'stripe_transfer_id' => null, // This will be set after payment processing
                ]
            );

            $total += $purchaseItem->price_at_purchase;
        }

        // Update the total amount in the purchase
        $purchase->update(['total_amount' => $total]);

        $transaction = $this->paymentService->createPayment([
            'amount' => $total,
            'currency' => 'usd',
            'description' => 'Digital books purchase',
            'purpose' => 'digital_book_purchase',
            'purpose_id' => $purchase->id,
            'meta_data' => [
                'book_ids' => $bookIds,
                'user_id' => $user->id,
                'purchase_id' => $purchase->id,
            ],
        ], $user);

        // If payment is successful, immediately update everything
        if (!($transaction instanceof JsonResponse)) {
            // Mark purchase as paid
            $purchase->update(['status' => 'paid']);

            // Add books to user's purchased books and update author wallets
            foreach ($purchase->items as $item) {
                // Add to user's purchased books
                if (!$user->purchasedBooks()->where('book_id', $item->book_id)->exists()) {
                    $user->purchasedBooks()->syncWithoutDetaching($item->book_id);
                }

                // Update author wallet immediately
                $author = $item->book->author;
                if ($author) {
                    $author->increment('wallet_balance', $item->author_payout_amount);

                    // Create payout transaction record
                    Transaction::create([
                        'id' => \Illuminate\Support\Str::uuid(),
                        'user_id' => $author->id,
                        'reference' => uniqid('pay_immediate_'),
                        'status' => 'succeeded',
                        'currency' => $purchase->currency ?? 'USD',
                        'amount' => $item->author_payout_amount,
                        'payment_provider' => 'app',
                        'description' => "Immediate author payout for DigitalBookPurchase ID: {$purchase->id}",
                        'type' => 'payout',
                        'direction' => 'credit',
                        'purpose_type' => 'digital_book_purchase',
                        'purpose_id' => $purchase->id,
                    ]);
                }

                // Update analytics
                $bookAnalytics = \App\Models\BookMetaDataAnalytics::firstOrCreate(
                    ['book_id' => $item->book_id],
                    ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                );
                $bookAnalytics->increment('purchases', 1);
                $bookAnalytics->save();
            }
        }

        if ($transaction instanceof JsonResponse) {
            $responseData = $transaction->getData(true);

            return $this->error(
                'An error occurred while initiating the books purchase process.',
                $transaction->getStatusCode(),
                $responseData['error'] ?? 'Unknown error from payment service.'
            );
        }

        return $this->success(
            [
                'transaction' => $transaction,
                'book_ids' => $bookIds,
            ],
            'Books purchase process initiated successfully.'
        );
    }
}
