<?php

namespace App\Http\Controllers\Book;

use App\Http\Controllers\Controller;
use App\Http\Resources\Book\BookResource;
use App\Http\Resources\ReadingProgress\ReadingProgressResource;
use App\Mail\Book\BookApproved;
use App\Mail\Book\BookDeclined;
use App\Mail\Book\BookDeleted;
use App\Mail\Books\BookCreatedNotification;
use App\Models\Book;
use App\Models\BookReviews;
use App\Models\DigitalBookPurchase;
use App\Models\DigitalBookPurchaseItem;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Notifications\Book\Milestone\MilestoneReachedNotification;
use App\Services\Book\BookService;
use App\Services\Book\PdfTocExtractorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// use App\Services\Notification\NotificationService;

// use App\Traits\ApiResponse;

class BookController extends Controller
{
    protected BookService $service;

    private $rules;

    // use ApiResponse;

    public function __construct(BookService $service)
    {
        // $this->middleware('auth:sanctum');
        // $this->middleware('role:admin')->except(['index','show']);
        $this->service = $service;

        $this->rules = [
            'books' => 'required|array|min:1',
            'books.*.title' => 'required|string|max:255',
            'books.*.sub_title' => 'nullable|string|max:255',
            'books.*.description' => 'required|string',
            'books.*.author_id' => 'required|exists:users,id',
            'books.*.authors' => 'required|array',
            'books.*.authors.*' => 'required|exists:users,id',
            'books.*.isbn' => 'required|string|unique:books,isbn',
            'books.*.table_of_contents' => 'required|json',
            'books.*.tags' => 'nullable|array',
            'books.*.tags.*' => 'string|max:50',
            'books.*.category' => 'nullable|array',
            'books.*.categories' => 'nullable|array',
            'books.*.category.*' => 'exists:categories,id',
            'books.*.categories.*' => 'exists:categories,id',
            'books.*.genres' => 'nullable|array',
            'books.*.genres.*' => 'string|max:50',
            'books.*.publication_date' => 'nullable|date',
            'books.*.language' => 'nullable|array',
            'books.*.language.*' => 'string|max:50',
            'books.*.cover_image' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            // 'books.*.cover_image.url'            => 'nullable|url',
            // 'books.*.cover_image.public_id'      => 'nullable|string',
            'books.*.format' => 'nullable|string|max:50',
            'books.*.files' => 'required|array',
            'books.*.files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:20480',
            // 'books.*.files.*.url'                => 'required_with:books.*.files|url',
            // 'books.*.files.*.public_id'          => 'nullable|string',
            'books.*.target_audience' => 'nullable|array',
            'books.*.target_audience.*' => 'string|max:50',
            'books.*.pricing' => 'nullable|array',
            'books.*.pricing.actual_price' => 'required_with:books.*.pricing|numeric|min:0',
            'books.*.pricing.discounted_price' => 'nullable|numeric|min:0',
            'books.*.actual_price' => 'nullable|numeric|min:0',
            'books.*.discounted_price' => 'nullable|numeric|min:0',
            'books.*.currency' => 'nullable|string|size:3',
            'books.*.availability' => 'nullable|array',
            'books.*.availability.*' => 'string',
            'books.*.file_size' => 'nullable|string|max:50',
            'books.*.drm_info' => 'nullable|json',
            'books.*.meta_data' => 'nullable|array',
            'books.*.meta_data.pages' => 'required|numeric|min:0',
            'books.*.publisher' => 'nullable|string|max:255',
            'books.*.archived' => 'boolean',
            'books.*.deleted' => 'boolean',
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Filter for visible books (public visibility and any status except rejected)
        $query = Book::query()
            ->where('visibility', 'public')
            ->whereIn('status', ['pending', 'approved', 'published']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Handle classification parameter
        if ($request->filled('classification')) {
            $query = $this->applyClassification($query, $request->classification);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', "%{$request->search}%");
        }

        $books = $query->with([
            'categories:id,name',
            'authors:id,name',
            'reviews:id,book_id,rating'
        ])->paginate($request->get('items_per_page', 10));

        return BookResource::collection($books, true); // true = listing mode
    }

    private function applyClassification($query, $classification)
    {
        switch ($classification) {
            case 'new_arrivals':
                return $query->where('created_at', '>=', now()->subDays(30))
                    ->orderBy('created_at', 'desc');

            case 'trending':
                return $query->orderByDesc('views_count');

            case 'top_picks':
                return $query->whereIn('id', function ($subquery) {
                    $subquery->select('book_id')
                        ->from('book_reviews')
                        ->where('rating', '>=', 4.0)
                        ->groupBy('book_id')
                        ->havingRaw('AVG(rating) >= 4.0');
                })->orderBy('id');

            default:
                return $query->inRandomOrder();
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), $this->rules);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            $created = $this->service->createMultiple($validator->validated()['books']);

            // dd($created);
            foreach ($created as $book) {
                // dd($book);
                // Notify the author about the book creation
                // $book->author->notify(new BookApproved($book));
                $this->notifier()->send(
                    User::find($book->author_id),
                    'New Book Created',
                    'Your book "' . $book->title . '" has been created successfully.',
                    ['in-app', 'email'],
                    $book,
                    new BookCreatedNotification(
                        $book,
                        User::find($book->author_id)->name ?? 'Author'
                    ),
                );
            }

            return $this->success(
                BookResource::collection($created),
                'Books created successfully!',
                201
            );
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->error(
                'An error occurred while creating books.',
                500,
                null,
                $e
            );
        } catch (\Exception $e) {
            return $this->error(
                'An error occurred while creating books.',
                500,
                null,
                $e
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $book = Book::with([
                'authors:id,name,email,profile_picture,bio',
                'categories',
                'reviews.user:id,name,email,profile_picture',
                'analytics',
                'bookmarkedBy:id',
                'purchasers:id'
            ])->findOrFail($id);

            // Fetch similar books (by shared categories)
            $similarBooks = Book::whereHas('categories', function ($q) use ($book) {
                $q->whereIn('categories.id', $book->categories->pluck('id'));
            })
                ->where('id', '<>', $book->id)
                ->take(5)
                ->get();

            return $this->success(
                [
                    'book' => new BookResource($book),
                    'similar_books' => BookResource::collection($similarBooks),
                ],
                'Book details retrieved successfully.',
                200
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to retrieve book details.',
                500,
                null,
                $e->getMessage()
            );
        }
    }

    /**
     * For authors: get books authored by the current user.
     */
    public function myBooks(Request $request)
    {
        $user = $request->user();

        $query = Book::whereHas('authors', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhere('isbn', 'ilike', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Visibility filter
        if ($request->filled('visibility')) {
            $query->where('visibility', $request->input('visibility'));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $books = $query->with([
            'authors:id,name,email,profile_picture,bio',
            'categories:id,name',
            'analytics'
        ])->paginate($request->input('per_page', 20));

        if ($books->isEmpty()) {
            return $this->error(
                'No books found for this author.',
                404
            );
        }

        return $this->success(
            BookResource::collection($books),
            'Books authored by you retrieved successfully.',
            200
        );
    }

    /**
     * Start or update reading progress.
     */
    public function startReading(Request $request, $bookId)
    {
        $user = $request->user();

        // Check if user has purchased the book
        if (! $user->purchasedBooks()->where('book_id', $bookId)->exists()) {
            return $this->error(
                'You must purchase the book before reading it.',
                403
            );
        }

        $validator = Validator::make($request->all(), [
            // 'progress' => 'required|integer|min:0|max:100',
            'page' => 'required|string',
            'bookmarks' => 'nullable|array',
            'session_duration' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error(
                'Validation failed',
                400,
                $validator->errors()
            );
        }

        $validated = $validator->validated();

        // Check if the book exists
        $book = Book::findOrFail($bookId);

        if (! $book) {
            return $this->error(
                'Book not found',
                404
            );
        }

        // Do not allow user to start reading their own book
        if ($book->author_id == $user->id) {
            return $this->error(
                'You cannot read your own book',
                403
            );
        }

        // Total pages count from book
        $totalPages = $book->meta_data['pages'] ?? 0;

        // Resolve error if book has no page count
        if ($totalPages <= 0) {
            return $this->error(
                'Book has no page count',
                400
            );
        }

        $progress = min(round((intval($validated['page']) / intval($totalPages)) * 100), 100);

        $record = $book->readingProgress()->updateOrCreate(
            ['book_id' => $bookId, 'user_id' => $user->id],
            [
                // 'user_id' => $user->id,
                // 'book_id' => $bookId,
                'progress' => $progress,
                'page' => $validated['page'] ?? '',
                'bookmarks' => json_encode($validated['bookmarks'] ?? []),
                'session_duration' => json_encode($validated['session_duration'] ?? []),
                'last_accessed' => now(),
                'last_read_at' => now(),
            ]
        );

        // Trigger milestone logic
        $this->handleMilestoneNotification($user, $book, $progress);

        return $this->success(
            new ReadingProgressResource($record),
            'Reading progress updated successfully.',
            200
        );
    }

    private function handleMilestoneNotification($user, $book, $progress)
    {
        $milestones = [25, 50, 100];
        foreach ($milestones as $milestone) {
            $key = "milestone_{$milestone}_notified";
            $progressKey = "book_{$book->id}_{$key}";

            if ($progress >= $milestone && ! cache()->has($progressKey)) {
                // TODO: Send notification (Firebase, email, etc.)
                $this->notifier()->send(
                    $user,
                    "{$milestone}% Milestone Reached in '{$book->title}'!",
                    "Congratulations! You've reached {$milestone}% of '{$book->title}'. Keep going!",
                    ['in-app', 'email'],
                    $book,
                    new MilestoneReachedNotification($book, $milestone)
                );
                // Notification::route('mail', $user->email)->notify(new MilestoneReachedNotification($book, $milestone));

                cache()->put($progressKey, true, now()->addDays(30)); // prevent spamming
            }
        }
    }

    public function userProgress(Request $request)
    {
        try {
            $user = $request->user();
            $cacheKey = 'user_reading_progress_' . $user->id . '_' . md5(json_encode($request->all()));

            $progress = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user, $request) {
                $query = ReadingProgress::with([
                    'book.authors',
                    'book.categories',
                    'book.analytics',
                ])
                    ->where('user_id', $user->id)
                    ->whereHas('book');

                if ($request->filled('search')) {
                    $search = $request->input('search');
                    $query->whereHas('book', function ($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                }

                if ($request->input('sort_by') === 'recommended') {
                    // Sort by user's top completed % or analytics data
                    $query->join('book_meta_data_analytics as a', 'reading_progress.book_id', '=', 'a.book_id')
                        ->orderBy('a.recommendation_score', 'desc') // Assuming such a column exists
                        ->select('reading_progress.*');
                } elseif ($request->input('sort_by') === 'progress') {
                    $query->orderBy('progress', 'desc');
                } else {
                    $query->orderBy('last_accessed', 'desc');
                }

                return $query->paginate($request->input('items_per_page', 15));
            });

            return $this->success(
                ReadingProgressResource::collection($progress),
                'Reading progress fetched successfully',
                200
            );
        } catch (\Exception $e) {
            // dd($e);
            return $this->error(
                'Failed to fetch reading progress',
                500,
                null,
                $e
            );
        }
    }

    /**
     * Post a review for a book.
     */
    public function postReview(Request $request, $bookId)
    {
        $user_id = $request->user()->id;
        $validation = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        if ($validation->fails()) {
            return $this->error(
                'Validation failed',
                400,
                $validation->errors()
            );
        }

        // Check if the book exists
        $book = Book::find($bookId);
        if (! $book) {
            return $this->error(
                'Book not found',
                404
            );
        }

        // Do not allow reviewing own book
        if ($book->author_id == $user_id) {
            return $this->error(
                'You cannot review your own book',
                403
            );
        }

        // Check if the user has already reviewed this book
        $existingReview = BookReviews::where('user_id', $user_id)
            ->where('book_id', $bookId)
            ->first();

        if ($existingReview) {
            return $this->error(
                'You have already reviewed this book',
                409
            );
        }

        $review = BookReviews::create([
            'user_id' => $user_id,
            'book_id' => $bookId,
            'rating' => $request->input('rating'),
            'comment' => $request->input('comment'),
        ]);

        // Notify the author about the new review
        $this->notifier()->send(
            User::find($book->author_id),
            'New Review on Your Book',
            "Your book '{$book->title}' has received a new review.",
            ['in-app', 'email'], // push notifications can be added later(push)
            $book,
            // new \App\Notifications\Book\BookReviewed($review)
        );

        return $this->success(
            $review,
            'Review posted successfully',
            201
        );
    }

    /**
     * Bookmark a book.
     * Assumes a many-to-many relationship 'bookmarks' is defined on User.
     */
    public function bookmark(Request $request, $bookId)
    {
        $user = $request->user();

        // Do not allow use to bookmark their own book
        $book = Book::find($bookId);
        if (! $book) {
            return $this->error(
                'Book not found',
                404
            );
        }

        if ($book->author_id == $user->id) {
            return $this->error(
                'You cannot bookmark your own book',
                403
            );
        }

        // Check if the user has already bookmarked this book
        $existingBookmark = $user->bookmarks()->where('book_id', $bookId)->first();
        if ($existingBookmark) {
            return $this->error(
                'You have already bookmarked this book',
                409
            );
        }

        $user->bookmarks()->syncWithoutDetaching([$bookId]);

        return response()->json([
            'data' => [
                'book_id' => (int) $bookId,
                'user_id' => $user->id,
                'bookmarked' => true
            ],
            'code' => 200,
            'message' => 'Book bookmarked successfully'
        ]);
    }

    /**
     * All bookmarks.
     */
    public function getAllBookmarks(Request $request)
    {
        try {
            $user = $request->user();
            $perPage = $request->input('items_per_page', 10);
            $search = $request->input('search');
            $sortBy = $request->input('sort_by', 'recommended');

            $query = Book::query()
                ->whereHas('bookmarkedBy', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->with([
                    'authors:id,name',
                    'categories:id,name',
                    'analytics:id,book_id,views,downloads,likes',
                    'readingProgress' => fn($q) => $q->where('user_id', $user->id),
                ]);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($sortBy === 'recommended') {
                $query->leftJoin('book_meta_data_analytics as a', 'books.id', '=', 'a.book_id')
                    ->orderByDesc('a.likes')
                    ->orderByDesc('a.views')
                    ->select('books.*');
            } else {
                $query->orderBy('books.created_at', 'desc');
            }

            $books = $query->paginate($perPage);

            return $this->success(
                [
                    'books' => BookResource::collection($books),
                    'meta' => [
                        'current_page' => $books->currentPage(),
                        'last_page' => $books->lastPage(),
                        'total' => $books->total(),
                    ],
                ],
                'Bookmarks retrieved successfully',
                200
            );
        } catch (\Throwable $th) {
            return $this->error(
                'Failed to fetch bookmarks',
                500,
                null,
                $th
            );
        }
    }

    // remove from bookmark
    public function removeBookmark(Request $request, $bookId)
    {
        try {
            $user = $request->user();

            // Check if the book exists
            $book = Book::find($bookId);
            if (! $book) {
                return $this->error(
                    'Book not found',
                    404
                );
            }

            // Check if the user has already bookmarked this book
            $existingBookmark = $user->bookmarks()->where('book_id', $bookId)->first();
            if (! $existingBookmark) {
                return $this->error(
                    'You have not bookmarked this book',
                    409
                );
            }

            $user->bookmarks()->detach($bookId);

            return response()->json([
                'data' => [
                    'book_id' => (int) $bookId,
                    'user_id' => $user->id,
                    'bookmarked' => false
                ],
                'code' => 200,
                'message' => 'Bookmark removed successfully'
            ]);
        } catch (\Throwable $th) {
            return $this->error(
                'Failed to remove bookmark',
                500,
                null,
                $th
            );
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Book $book)
    {
        try {
            $user = $request->user();

            // Authorization: Only book authors or admins can update books
            if ($book->author_id !== $user->id && !$user->hasRole(['admin', 'superadmin'])) {
                return $this->error('Unauthorized. You can only update your own books.', 403);
            }

            // Validation rules for updating a book
            $rules = [
                'title' => 'sometimes|string|max:255',
                'sub_title' => 'sometimes|nullable|string|max:255',
                'description' => 'sometimes|string',
                'isbn' => 'sometimes|string|unique:books,isbn,' . $book->id,
                'table_of_contents' => 'sometimes|array',
                'tags' => 'sometimes|nullable|array',
                'tags.*' => 'string|max:50',
                'categories' => 'sometimes|nullable|array',
                'categories.*' => 'exists:categories,id',
                'genres' => 'sometimes|nullable|array',
                'genres.*' => 'string|max:50',
                'publication_date' => 'sometimes|nullable|date',
                'language' => 'sometimes|nullable|array',
                'language.*' => 'string|max:50',
                'format' => 'sometimes|nullable|string|max:50',
                'target_audience' => 'sometimes|nullable|array',
                'target_audience.*' => 'string|max:50',
                'actual_price' => 'sometimes|nullable|numeric|min:0',
                'discounted_price' => 'sometimes|nullable|numeric|min:0',
                'currency' => 'sometimes|nullable|string|size:3',
                'availability' => 'sometimes|nullable|array',
                'availability.*' => 'string',
                'file_size' => 'sometimes|nullable|string|max:50',
                'publisher' => 'sometimes|nullable|string|max:255',
                'meta_data' => 'sometimes|nullable|array',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            $validated = $validator->validated();

            // Handle categories relationship separately
            $categories = null;
            if (isset($validated['categories'])) {
                $categories = $validated['categories'];
                unset($validated['categories']);
            }

            // Update the book with validated data
            $book->update($validated);

            // Update categories relationship if provided
            if ($categories !== null) {
                $book->categories()->sync($categories);
            }

            // Reload the book with relationships
            $book->load(['authors', 'categories', 'reviews', 'analytics']);

            return $this->success(
                new BookResource($book),
                'Book updated successfully.'
            );
        } catch (\Throwable $th) {
            return $this->error('Failed to update book', 500, null, $th);
        }
    }

    /**
     * Toggle book visibility between public and private.
     */
    public function toggleVisibility(Request $request, Book $book)
    {
        try {
            $user = $request->user();

            // Authorization: Only book authors or admins can toggle visibility
            if ($book->author_id !== $user->id && !$user->hasRole(['admin', 'superadmin'])) {
                return $this->error('Unauthorized. You can only toggle visibility of your own books.', 403);
            }

            // Validation for visibility value
            $validator = Validator::make($request->all(), [
                'visibility' => 'sometimes|in:public,private'
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            // If visibility is provided in request, use it; otherwise toggle
            if ($request->has('visibility')) {
                $newVisibility = $request->input('visibility');
            } else {
                // Toggle between public and private
                $newVisibility = $book->visibility === 'public' ? 'private' : 'public';
            }

            // Update the book visibility
            $book->update(['visibility' => $newVisibility]);

            // Reload the book with relationships
            $book->load(['authors', 'categories', 'reviews', 'analytics']);

            return $this->success(
                [
                    'book' => new BookResource($book),
                    'previous_visibility' => $book->visibility === 'public' ? 'private' : 'public',
                    'current_visibility' => $newVisibility
                ],
                "Book visibility updated to {$newVisibility} successfully."
            );
        } catch (\Throwable $th) {
            return $this->error('Failed to toggle book visibility', 500, null, $th);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Book $book, Request $request)
    {
        try {
            $validator = validator($request->all(), [
                'reason' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors(), 422, 'Validation failed.');
            }

            $deleted = $this->service->deleteBook($book, $request->input('reason'));

            if ($deleted) {
                $this->notifier()->send(
                    User::find($book->author_id),
                    'New Book Created',
                    'Your book "' . $book->title . '" has been deleted. Reason: ' . $request->input('reason'),
                    ['in-app', 'email'],
                    $book,
                    new BookDeleted(
                        $book,
                        $request->input('reason'),
                        User::find($book->author_id)
                    ),
                );

                return $this->success(null, 'Book deleted successfully.');
            }

            return $this->error('Failed to delete book.', 500);
        } catch (\Throwable $th) {
            return $this->error('Failed to delete book.', 500, null, $th);
        }
    }

    /**
     * Extracts preview details (such as table of contents) from an uploaded PDF book file.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function extractPreview(Request $request)
    {
        try {
            // dd($request->all());
            $validator = Validator::make($request->all(), [
                'book' => 'required|file|mimes:pdf|max:20480', // max 20MB
            ]);

            if ($validator->fails()) {
                return $this->error('Validation error', 400, $validator->errors());
            }

            $file = $request->file('book');
            $path = $file->store('books_uploads');

            $absolutePath = storage_path("app/private/{$path}");

            $pdfService = new PdfTocExtractorService;
            $extracted = $pdfService->extractBookDetails(storage_path("app/private/{$path}"));

            // Delete temp file after processing
            Storage::delete($absolutePath);

            return $this->success($extracted, 'Extraction successful');
        } catch (\Throwable $th) {
            // dd($th);
            return $this->error($th->getMessage(), 500, null, $th);
        }
    }

    /**
     * Get all books for admin with pagination, filtering, sorting, and searching.
     */
    public function getAllBooks(Request $request)
    {
        try {
            $query = Book::with(['authors', 'categories', 'reviews.user', 'analytics']);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('visibility')) {
                $query->where('visibility', $request->input('visibility'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%");
                });
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sortBy, $sortDir);

            // Pagination
            $perPage = $request->input('items_per_page', 25);
            $books = $query->paginate($perPage);

            return BookResource::collection($books)
                ->additional([
                    'code' => 200,
                    'message' => 'Books retrieved successfully!',
                    'error' => null,
                ]);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve books: ' . $e->getMessage(), 500, null, $e);
        }
    }

    // ================================================================================================================================================
    // ====================                                            BOOK AUDIT TRAILS                                           ====================
    // ================================================================================================================================================
    /**
     * Handle book audit actions: request review changes, approve, or decline a book.
     *
     * @param  int  $bookId
     * @return \Illuminate\Http\JsonResponse
     */
    public function auditAction(Request $request, $action, $bookId)
    {
        try {
            $user = $request->user(); // Admin user
            // $action = $request->input('action'); // 'request_changes', 'approve', 'decline', 'decline_with_review'
            $note = $request->input('note');
            $reviewNotes = $request->input('review_notes');
            $rejectionNote = $request->input('rejection_note');

            // Correct way:
            $book = Book::with(['authors', 'categories'])->findOrFail($bookId);

            if ($action === 'request_changes') {
                if ($book->status !== 'needs_changes') {
                    $book->status = 'needs_changes';
                    $book->save();
                }

                // Create BookAudit record
                $audit = \App\Models\BookAudit::create([
                    'admin_id' => $user->id,
                    'book_id' => $book->id,
                    'action' => 'requested_changes',
                    'note' => $note,
                    'acted_at' => now(),
                ]);

                // Notify all authors of the book
                foreach ($book->authors as $author) {
                    $this->notifier()->send(
                        $author,
                        'Book Changes Requested',
                        "Changes have been requested for your book '{$book->title}'. Please review the feedback provided.",
                        ['in-app', 'email'],
                        $book
                    );
                    // $author->notify(new BookChangesRequested($book, $note));
                }

                $book->refresh();

                return $this->success(['audit' => $audit, 'book' => $book], 'Requested changes for the book.');
            }

            if ($action === 'approve') {
                $book->status = 'approved';
                $book->visibility = 'public';
                $book->approved_by = $user->id;
                $book->approved_at = now();
                if ($reviewNotes) {
                    $book->review_notes = $reviewNotes;
                }
                $book->save();

                // dd($book);
                // Ensure authors are loaded
                if ($book->authors->isEmpty()) {
                    return $this->error('No authors found for this book.', 404);
                }

                foreach ($book->authors as $author) {
                    $this->notifier()->send(
                        $author,
                        'Book Approved',
                        "Your book '{$book->title}' has been approved.",
                        ['in-app', 'email'],
                        $book,
                        new BookApproved($book)
                    );
                    // $author->notify(new BookApproved($book));
                }

                $book->authors->transform(function ($author) {
                    return [
                        'id' => $author->id,
                        'name' => $author->name,
                        'email' => $author->email,
                    ];
                });

                $book->refresh();

                return $this->success($book, 'Book approved successfully.');
            }

            if ($action === 'decline' || $action === 'decline_with_review') {
                if (! $reviewNotes && !$rejectionNote) {
                    return $this->error('Review notes or rejection note are required to decline a book.');
                }
                $book->status = 'declined';
                $book->review_notes = $reviewNotes;
                $book->rejection_note = $rejectionNote;
                $book->save();

                if ($action === 'decline_with_review') {
                    $audit = \App\Models\BookAudit::create([
                        'admin_id' => $user->id,
                        'book_id' => $book->id,
                        'action' => 'declined',
                        'note' => $reviewNotes,
                        'acted_at' => now(),
                    ]);
                }

                $reason = $reviewNotes ?? $note ?? "Your book, '{$book->title}' submission has been declined. Please review the feedback provided.";

                // Ensure authors are loaded
                if ($book->authors->isEmpty()) {
                    return $this->error('No authors found for this book.', 404);
                }

                // Notify all authors of the book
                foreach ($book->authors as $author) {
                    $this->notifier()->send(
                        $author,
                        'Book Declined',
                        $reason,
                        ['in-app', 'email'],
                        $book,
                        new BookDeclined($book, $reason)
                    );
                    // $author->notify(new BookDeclined($reason));
                }
                // Notification::send($book->authors, new BookDeclined($reason));

                // $book->author->notify(new BookDeclined($reason));
                $book->authors->transform(function ($author) {
                    return [
                        'id' => $author->id,
                        'name' => $author->name,
                        'email' => $author->email,
                    ];
                });

                $book->refresh();

                return $this->success(['audit' => $audit ?? null, 'book' => $book], 'Book declined.');
            }

            return $this->error('Invalid action.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Book not found.', 400, $e->getMessage(), $e);
        } catch (\Exception $e) {
            return $this->error('An error occurred while processing the audit action.', 400, $e->getMessage(), $e);
        }
    }

    public function purchaseBooks(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'books' => 'required|array',
                'books.*' => 'required|integer|exists:books,id',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed.', 422, $validator->errors());
            }

            $user = $request->user();
            $bookIds = $request->input('books');

            // Check if the user already owns any of the books
            $ownedBooks = $user->purchasedBooks()->whereIn('book_id', $bookIds)->pluck('book_id');

            if ($ownedBooks->isNotEmpty()) {
                $alreadyOwnedIds = $ownedBooks->toArray();
                $newBookIds = array_diff($bookIds, $alreadyOwnedIds);

                if (empty($newBookIds)) {
                    return $this->error('You already own all the selected books.', 409);
                }

                $conflictingBooks = Book::whereIn('id', $alreadyOwnedIds)->pluck('title')->implode(', ');

                return $this->error('You already own the following book(s): ' . $conflictingBooks, 409);
            }

            // Fetch books data
            $books = Book::whereIn('id', $bookIds)->get();

            // Create digital book purchase records
            foreach ($bookIds as $bookId) {
                DigitalBookPurchase::create([
                    'user_id' => $user->id,
                    'book_id' => $bookId,
                    'purchase_price' => $books->find($bookId)->actual_price ?? 0,
                    'currency' => 'USD',
                    'status' => 'completed',
                ]);
            }

            // Add books to user's purchased books relationship
            $user->purchasedBooks()->syncWithoutDetaching($bookIds);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('One or more books not found.', 404, $e->getMessage(), $e);
        } catch (\Exception $e) {
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
        }
    }

    /**
     * Get reviews for a specific book.
     */
    public function getReviews(Request $request, $bookId)
    {
        try {
            $book = Book::findOrFail($bookId);

            $query = BookReviews::with('user:id,name,email,profile_picture')
                ->where('book_id', $bookId);

            // Filter by rating
            if ($request->filled('rating')) {
                $query->where('rating', $request->input('rating'));
            }

            // Search in comments
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where('comment', 'ilike', "%{$search}%");
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDir = $request->input('sort_dir', 'desc');
            $query->orderBy($sortBy, $sortDir);

            $reviews = $query->paginate($request->input('per_page', 15));

            return $this->success(
                $reviews,
                'Reviews retrieved successfully.',
                200
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to retrieve reviews.',
                500,
                null,
                $e->getMessage()
            );
        }
    }
}
