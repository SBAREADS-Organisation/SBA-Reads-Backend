<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorLesson;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSlot;
use App\Models\CreatorStore;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\Creators\CreatorPresenter;
use App\Services\Creators\CreatorService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A creator managing their SBA Creators store, products and lessons. */
class CreatorStudioController extends Controller
{
    public function __construct(private CreatorPresenter $presenter)
    {
    }

    private function myStore(Request $request): ?CreatorStore
    {
        return CreatorStore::where('user_id', $request->user()->id)->first();
    }

    private function myProduct(Request $request, int $id): ?CreatorProduct
    {
        return CreatorProduct::where('id', $id)->where('user_id', $request->user()->id)->first();
    }

    private function uniqueSlug(string $text, callable $taken): string
    {
        $base = Str::slug($text) ?: 'item';
        $slug = $base;
        for ($i = 2; $taken($slug); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function validationError($validator): JsonResponse
    {
        return $this->error($validator->errors()->first(), 422, $validator->errors());
    }

    public function plans(): JsonResponse
    {
        return $this->success([
            'plans' => CreatorService::plansWithPrices(),
            'billing' => config('creators.billing'),
            'trial_days' => config('creators.trial_days'),
        ], 'Plans retrieved.');
    }

    public function show(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->success(['store' => null, 'plans' => CreatorService::plansWithPrices()], 'No store yet.');
        }

        $paid = CreatorPurchase::where('store_id', $store->id)->where('status', 'paid');

        return $this->success([
            'store' => $this->presenter->store($store, true),
            'plans' => CreatorService::plansWithPrices(),
            'stats' => [
                'products' => $store->products()->count(),
                'published' => $store->products()->where('status', 'published')->count(),
                'sales' => (clone $paid)->where('price', '>', 0)->count(),
                'students' => (clone $paid)->distinct('buyer_id')->count('buyer_id'),
                'earnings' => round((float) (clone $paid)->sum('creator_earning'), 2),
                'currency' => 'NGN',
            ],
        ], 'Store retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->account_type !== 'author') {
            return $this->error('Switch to your author account to start selling. You can add one from your profile for free.', 403);
        }
        if ($this->myStore($request)) {
            return $this->error('You already have a store.', 409);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'slug' => 'nullable|string|max:60|regex:/^[a-z0-9-]+$/|not_in:unsubscribe|unique:creator_stores,slug',
            'bio' => 'nullable|string|max:2000',
            'fee_payer' => 'nullable|in:creator,buyer',
        ], ['slug.regex' => 'Your store link can only use lowercase letters, numbers and dashes.', 'slug.unique' => 'That store link is taken. Please choose another.', 'slug.not_in' => 'That store link is taken. Please choose another.']);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $store = CreatorStore::create([
            'user_id' => $user->id,
            'name' => $request->input('name'),
            'slug' => $request->input('slug') ?: $this->uniqueSlug($request->input('name'), fn ($s) => $s === 'unsubscribe' || CreatorStore::where('slug', $s)->exists()),
            'bio' => $request->input('bio'),
            'fee_payer' => $request->input('fee_payer', 'creator'),
            'plan' => 'starter',
            'trial_ends_at' => now()->addDays((int) config('creators.trial_days')),
        ]);

        return $this->success(['store' => $this->presenter->store($store, true)], 'Your store is ready. Your first month of Pro is free.', 201);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->error('Create your store first.', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:120',
            'slug' => ['sometimes', 'required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', 'not_in:unsubscribe', Rule::unique('creator_stores', 'slug')->ignore($store->id)],
            'bio' => 'sometimes|nullable|string|max:2000',
            'fee_payer' => 'sometimes|in:creator,buyer',
            'logo' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], ['slug.regex' => 'Your store link can only use lowercase letters, numbers and dashes.', 'slug.unique' => 'That store link is taken. Please choose another.', 'slug.not_in' => 'That store link is taken. Please choose another.']);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $changes = $request->only(['name', 'slug', 'bio', 'fee_payer']);
        if ($request->hasFile('logo')) {
            $upload = app(CloudinaryMediaUploadService::class)->upload($request->file('logo'), 'creator_cover');
            if ($upload instanceof JsonResponse) {
                return $this->error('We could not upload that image. Please try another.', 422);
            }
            $changes['logo_url'] = (string) $upload['url'];
        }
        $store->update($changes);

        return $this->success(['store' => $this->presenter->store($store->fresh(), true)], 'Store updated.');
    }

    /** Starts payment for one month of a paid plan. */
    public function buyPlan(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->error('Create your store first.', 404);
        }
        $validator = Validator::make($request->all(), [
            'plan' => 'required|in:pro,business',
            'period' => 'sometimes|in:' . implode(',', array_keys(config('creators.billing'))),
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $plan = $request->input('plan');
        $period = $request->input('period', 'monthly');
        $months = (int) config("creators.billing.{$period}.months");
        $price = CreatorService::planPrice($plan, $period);
        $transaction = app(PaymentService::class)->createPayment([
            'amount' => $price,
            'currency' => 'NGN',
            'description' => 'SBA Creators ' . config("creators.plans.{$plan}.name") . ' plan (' . ($months === 1 ? '1 month' : "{$months} months") . ')',
            'purpose' => 'creator_plan',
            'purpose_id' => $store->id,
            'meta_data' => ['store_id' => $store->id, 'plan' => $plan, 'period' => $period, 'months' => $months],
        ], $request->user());

        if ($transaction instanceof JsonResponse) {
            return $this->error('We could not start your payment. Please try again.', 502);
        }

        return $this->success([
            'authorization_url' => $transaction->payment_client_secret,
            'reference' => $transaction->reference,
            'amount' => $price,
            'months' => $months,
        ], 'Complete your payment to activate your plan.');
    }

    public function products(Request $request): JsonResponse
    {
        $products = CreatorProduct::where('user_id', $request->user()->id)
            ->withCount(['lessons', 'purchases as sales_count' => fn ($q) => $q->where('status', 'paid')])
            ->latest()
            ->get();

        return $this->success(['products' => $products->map(fn ($p) => $this->presenter->product($p, true))], 'Products retrieved.');
    }

    private function productRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'type' => [$required, Rule::in(CreatorProduct::TYPES)],
            'title' => "{$required}|string|max:160",
            'summary' => 'sometimes|nullable|string|max:300',
            'description' => 'sometimes|nullable|string|max:20000',
            'price' => ["{$required}", 'numeric', 'min:0', 'max:' . config('creators.max_price_ngn'), function ($attribute, $value, $fail) {
                if ($value > 0 && $value < config('creators.min_price_ngn')) {
                    $fail('The lowest price is ₦' . config('creators.min_price_ngn') . ', or ₦0 to give it away free.');
                }
            }],
            'cover' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
            // Events
            'starts_at' => 'sometimes|nullable|date',
            'ends_at' => 'sometimes|nullable|date|after_or_equal:starts_at',
            'timezone' => 'sometimes|nullable|timezone',
            'location' => 'sometimes|nullable|string|max:255',
            'online_url' => 'sometimes|nullable|url:http,https|max:2048',
            'capacity' => 'sometimes|nullable|integer|min:1|max:1000000',
            // Coaching
            'duration_minutes' => 'sometimes|nullable|integer|min:10|max:480',
            // Selling tools
            'instalments_max' => 'sometimes|nullable|integer|in:2,3,4',
            'upsell_product_id' => 'sometimes|nullable|integer',
            'upsell_price' => 'sometimes|nullable|numeric|min:0',
            'bonus_book_id' => 'sometimes|nullable|integer',
        ];
    }

    private const EVENT_FIELDS = ['starts_at', 'ends_at', 'timezone', 'location', 'online_url', 'capacity'];

    private const COACHING_FIELDS = ['duration_minutes', 'timezone', 'location', 'online_url'];

    /** The extra fields each kind of product keeps. */
    private function typeFields(string $type): array
    {
        return match ($type) {
            'event' => self::EVENT_FIELDS,
            'coaching' => self::COACHING_FIELDS,
            default => [],
        };
    }

    public function createProduct(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->error('Create your store first.', 404);
        }
        $validator = Validator::make($request->all(), $this->productRules(true));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $product = CreatorProduct::create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'type' => $request->input('type'),
            'title' => $request->input('title'),
            'slug' => $this->uniqueSlug($request->input('title'), fn ($s) => CreatorProduct::where('store_id', $store->id)->where('slug', $s)->exists()),
            'summary' => $request->input('summary'),
            'description' => $request->input('description'),
            'price' => $request->input('price'),
            'currency' => 'NGN',
            'status' => 'draft',
        ] + $request->only($this->typeFields($request->input('type')))
          + ($request->input('type') === 'coaching' && ! $request->filled('duration_minutes') ? ['duration_minutes' => 60] : []));
        if ($request->hasFile('cover') && ($error = $this->saveCover($request, $product))) {
            return $error;
        }

        return $this->success(['product' => $this->presenter->product($product->fresh(), true)], 'Product created.', 201);
    }

    public function showProduct(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }

        return $this->success(['product' => $this->presenter->product($product, true, withLessons: true, withFiles: true)], 'Product retrieved.');
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }
        $rules = $this->productRules(false) + ['status' => ['sometimes', Rule::in(CreatorProduct::STATUSES)]];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $changes = $request->only(['title', 'summary', 'description', 'price']);
        $changes += $request->only($this->typeFields($product->type));
        if ($product->isEvent()) {
            if (isset($changes['capacity']) && $changes['capacity'] < ($sold = $product->purchases()->where('status', 'paid')->count())) {
                return $this->error("{$sold} tickets are already sold, so the limit can't be lower than that.", 422);
            }
        }
        if ($problem = $this->sellingTools($request, $product, $changes)) {
            return $this->error($problem, 422);
        }
        // A product with buyers keeps its type, so what people paid for doesn't change shape.
        if ($request->filled('type') && ! $product->purchases()->exists()) {
            $changes['type'] = $request->input('type');
        }

        if ($request->input('status') === 'published' && $product->status !== 'published') {
            if ($reason = $this->cannotPublish($request, $product, (float) ($changes['price'] ?? $product->price))) {
                return $this->error($reason, 422);
            }
            $changes['status'] = 'published';
            $changes['published_at'] = $product->published_at ?? now();
        } elseif ($request->filled('status')) {
            $changes['status'] = $request->input('status');
        }

        $product->update($changes);
        if ($request->hasFile('cover') && ($error = $this->saveCover($request, $product))) {
            return $error;
        }

        return $this->success(['product' => $this->presenter->product($product->fresh(), true, withLessons: true, withFiles: true)], 'Product saved.');
    }

    /** Checks and adds instalments, the checkout add-on and the bonus ebook to a product's changes. */
    private function sellingTools(Request $request, CreatorProduct $product, array &$changes): ?string
    {
        if ($request->exists('instalments_max')) {
            $max = $request->input('instalments_max') ? (int) $request->input('instalments_max') : null;
            if ($max && $product->type !== 'course') {
                return 'Only courses can be paid in instalments.';
            }
            $price = (float) ($changes['price'] ?? $product->price);
            if ($max && $price < (float) config('creators.instalment_min_ngn')) {
                return 'Instalments are for courses priced at ₦' . number_format((float) config('creators.instalment_min_ngn')) . ' or more.';
            }
            $changes['instalments_max'] = $max;
        }

        if ($request->exists('upsell_product_id')) {
            $offerId = $request->input('upsell_product_id') ? (int) $request->input('upsell_product_id') : null;
            if ($offerId) {
                $offer = CreatorProduct::where('store_id', $product->store_id)->find($offerId);
                if (! $offer || $offer->id === $product->id || ! in_array($offer->type, ['course', 'download'], true)) {
                    return 'Choose another course or download from your store as the add-on.';
                }
                $special = (float) $request->input('upsell_price', $product->upsell_price ?? $offer->price);
                if ($special < (float) config('creators.min_price_ngn') || $special > (float) $offer->price) {
                    return 'The add-on price must be between ₦' . config('creators.min_price_ngn') . ' and its normal price of ₦' . number_format((float) $offer->price) . '.';
                }
                $changes['upsell_product_id'] = $offer->id;
                $changes['upsell_price'] = $special;
            } else {
                $changes['upsell_product_id'] = null;
                $changes['upsell_price'] = null;
            }
        }

        if ($request->exists('bonus_book_id')) {
            $bookId = $request->input('bonus_book_id') ? (int) $request->input('bonus_book_id') : null;
            if ($bookId && ! \App\Models\Book::where('author_id', $request->user()->id)->where('status', 'approved')->whereKey($bookId)->exists()) {
                return 'Choose one of your own published SBA books.';
            }
            $changes['bonus_book_id'] = $bookId;
        }

        return null;
    }

    /** The creator's published SBA books, for the "include my ebook" option. */
    public function books(Request $request): JsonResponse
    {
        $books = \App\Models\Book::where('author_id', $request->user()->id)->where('status', 'approved')
            ->orderBy('title')->get(['id', 'title']);

        return $this->success(['books' => $books], 'Books retrieved.');
    }

    private function cannotPublish(Request $request, CreatorProduct $product, float $price): ?string
    {
        if ($product->store->suspended) {
            return 'Your store is paused. Please contact admin@sbareads.com.';
        }
        if ($price > 0 && $request->user()->kyc_status !== 'verified') {
            return 'Verify your identity before selling paid products, so we can pay you. Free products can be published now.';
        }
        if ($product->isEvent()) {
            // What this save will leave on the event, so clearing a field and publishing at once is checked properly.
            $value = fn (string $field) => $request->exists($field) ? $request->input($field) : $product->{$field};
            $startsAt = $value('starts_at') ? \Illuminate\Support\Carbon::parse($value('starts_at')) : null;
            if (! $startsAt || $startsAt->isPast()) {
                return 'Set a date and time in the future before publishing.';
            }
            if (! $value('location') && ! $value('online_url')) {
                return 'Add where it happens: a venue, an online link, or both.';
            }

            return null;
        }
        if ($product->isCoaching()) {
            $value = fn (string $field) => $request->exists($field) ? $request->input($field) : $product->{$field};
            if (! $value('duration_minutes')) {
                return 'Set how long each session lasts before publishing.';
            }
            if (! $value('location') && ! $value('online_url')) {
                return 'Add where sessions happen: a meeting link, a place, or both.';
            }
            if (! $product->slots()->open()->exists()) {
                return 'Add at least one time you are free before publishing.';
            }

            return null;
        }
        if ($product->isMembership() && $price <= 0) {
            return 'Set a monthly price before publishing a membership.';
        }
        if (! $product->lessons()->exists()) {
            return match ($product->type) {
                'course' => 'Add at least one lesson before publishing.',
                'membership' => 'Add your first post for members before publishing.',
                default => 'Upload the file buyers will download before publishing.',
            };
        }

        return null;
    }

    private function saveCover(Request $request, CreatorProduct $product): ?JsonResponse
    {
        $upload = app(CloudinaryMediaUploadService::class)->upload($request->file('cover'), 'creator_cover');
        if ($upload instanceof JsonResponse) {
            return $this->error('Your product was saved, but we could not upload the cover image. Please try another.', 422);
        }
        $product->update(['cover_url' => (string) $upload['url']]);

        return null;
    }

    public function deleteProduct(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }
        // Buyers keep access to what they paid for, so sold products are hidden rather than deleted.
        if ($product->purchases()->where('status', 'paid')->exists()) {
            $product->update(['status' => 'hidden']);

            return $this->success(null, 'This product has buyers, so it has been hidden from your store instead of deleted. Buyers keep their access.');
        }
        $keys = $product->lessons()->whereNotNull('file_key')->pluck('file_key')->all();
        $product->delete();
        $this->deleteFiles($keys);

        return $this->success(null, 'Product deleted.');
    }

    /** A short-lived link the browser uploads a lesson or download file to directly. */
    public function uploadUrl(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }
        $validator = Validator::make($request->all(), [
            'kind' => 'required|in:video,pdf,file',
            'file_name' => 'required|string|max:200',
            'size' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $kind = $request->input('kind');
        $rules = config("creators.uploads.{$kind}");
        $extension = strtolower(pathinfo($request->input('file_name'), PATHINFO_EXTENSION));
        if (! isset($rules['types'][$extension])) {
            return $this->error('That file type is not supported. Use ' . strtoupper(implode(', ', array_keys($rules['types']))) . '.', 422);
        }
        $store = $product->store;
        $limit = $store->uploadLimitMb($kind);
        if ($request->integer('size') > $limit * 1024 * 1024) {
            $nice = $limit >= 1024 ? rtrim(rtrim(number_format($limit / 1024, 1), '0'), '.') . ' GB' : "{$limit} MB";
            $plan = $store->activePlan();
            $bigger = collect(config('creators.plans'))->filter(fn ($p) => ($p['upload_mb'] ?? 0) > $limit)->keys()->first();
            $upgrade = in_array($kind, ['video', 'file'], true) && $bigger
                ? ' Upgrade to ' . config("creators.plans.{$bigger}.name") . ' for bigger files.'
                : '';

            return $this->error("That file is too large. Your " . config("creators.plans.{$plan}.name") . " plan allows up to {$nice} per file.{$upgrade}", 422);
        }

        $key = "creators/{$product->store_id}/{$product->id}/" . Str::uuid() . ".{$extension}";
        try {
            $result = Storage::disk('s3')->temporaryUploadUrl($key, now()->addMinutes(60), ['ContentType' => $rules['types'][$extension]]);
        } catch (\Throwable $e) {
            Log::error('Creator upload link failed: ' . $e->getMessage());

            return $this->error('We could not prepare your upload. Please try again.', 500);
        }

        return $this->success([
            'upload_url' => is_array($result) ? ($result['url'] ?? '') : (string) $result,
            'content_type' => $rules['types'][$extension],
            'file_key' => $key,
        ], 'Upload link ready.');
    }

    private function lessonRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => "{$required}|string|max:200",
            'kind' => [$required, Rule::in(CreatorLesson::KINDS)],
            'body' => 'sometimes|nullable|string|max:50000',
            'file_key' => 'sometimes|nullable|string|max:300',
            'file_name' => 'sometimes|nullable|string|max:200',
            'file_size' => 'sometimes|nullable|integer|min:0',
            'is_preview' => 'sometimes|boolean',
        ];
    }

    /** Only keys this product's upload links created may be attached, so nobody can point at another file. */
    private function badFileKey(CreatorProduct $product, ?string $key): bool
    {
        return $key !== null && ! str_starts_with($key, "creators/{$product->store_id}/{$product->id}/");
    }

    public function createLesson(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }
        $validator = Validator::make($request->all(), $this->lessonRules(true));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }
        if ($this->badFileKey($product, $request->input('file_key'))) {
            return $this->error('That upload does not belong to this product.', 422);
        }
        if ($request->input('kind') !== 'text' && ! $request->filled('file_key')) {
            return $this->error('Upload the file for this lesson first.', 422);
        }

        $lesson = $product->lessons()->create($request->only(['title', 'kind', 'body', 'file_key', 'file_name', 'file_size', 'is_preview'])
            + ['position' => (int) $product->lessons()->max('position') + 1]);

        return $this->success(['lesson' => $this->presenter->lesson($lesson, true)], 'Lesson added.', 201);
    }

    public function updateLesson(Request $request, int $id, int $lessonId): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        $lesson = $product?->lessons()->whereKey($lessonId)->first();
        if (! $lesson) {
            return $this->error('Lesson not found.', 404);
        }
        $validator = Validator::make($request->all(), $this->lessonRules(false));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }
        if ($this->badFileKey($product, $request->input('file_key'))) {
            return $this->error('That upload does not belong to this product.', 422);
        }

        $old = $lesson->file_key;
        $lesson->update($request->only(['title', 'kind', 'body', 'file_key', 'file_name', 'file_size', 'is_preview']));
        if ($old && $old !== $lesson->file_key) {
            $this->deleteFiles([$old]);
        }

        return $this->success(['lesson' => $this->presenter->lesson($lesson->fresh(), true)], 'Lesson saved.');
    }

    public function deleteLesson(Request $request, int $id, int $lessonId): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        $lesson = $product?->lessons()->whereKey($lessonId)->first();
        if (! $lesson) {
            return $this->error('Lesson not found.', 404);
        }
        if ($product->status === 'published' && $product->lessons()->count() === 1) {
            return $this->error($product->isMembership()
                ? 'A published membership needs at least one post. Add another first, or unpublish it.'
                : 'A published product needs at least one lesson. Add another first, or unpublish the product.', 422);
        }
        $key = $lesson->file_key;
        $lesson->delete();
        $this->deleteFiles([$key]);

        return $this->success(null, 'Lesson removed.');
    }

    public function reorderLessons(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }
        $ids = $request->input('lesson_ids');
        $own = $product->lessons()->pluck('id')->all();
        if (! is_array($ids) || array_diff($own, $ids) || array_diff($ids, $own)) {
            return $this->error('Send every lesson of this product, in the new order.', 422);
        }
        foreach (array_values($ids) as $position => $lessonId) {
            CreatorLesson::whereKey($lessonId)->update(['position' => $position + 1]);
        }

        return $this->success(null, 'Lessons reordered.');
    }

    public function attendees(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product || ! $product->isEvent()) {
            return $this->error('Event not found.', 404);
        }
        $tickets = $product->purchases()->with('buyer:id,name,email')->where('status', 'paid')->orderBy('paid_at')->get()
            ->map(fn (CreatorPurchase $ticket) => [
                'id' => $ticket->id,
                'name' => $ticket->buyer?->name ?: 'Guest',
                'email' => $ticket->buyer?->email,
                'code' => $ticket->ticket_code,
                'checked_in_at' => $ticket->checked_in_at?->toISOString(),
            ]);

        return $this->success([
            'tickets' => $tickets,
            'sold' => $tickets->count(),
            'checked_in' => $tickets->whereNotNull('checked_in_at')->count(),
            'capacity' => $product->capacity,
        ], 'Attendees retrieved.');
    }

    /** Checks a ticket holder in at the door by their ticket code. */
    public function checkIn(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product || ! $product->isEvent()) {
            return $this->error('Event not found.', 404);
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('code')));
        $ticket = $product->purchases()->with('buyer:id,name')->where('status', 'paid')->where('ticket_code', $code)->first();
        if (! $ticket) {
            return $this->error('No ticket for this event has that code.', 404);
        }
        if ($ticket->checked_in_at) {
            return $this->error(($ticket->buyer?->name ?: 'This guest') . ' was already checked in at ' . $ticket->checked_in_at->format('g:ia') . '.', 409);
        }
        $ticket->update(['checked_in_at' => now()]);

        return $this->success(['name' => $ticket->buyer?->name ?: 'Guest'], 'Checked in.');
    }

    /** A coach's times: free, held while someone pays, or booked (with who booked). */
    public function slots(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product || ! $product->isCoaching()) {
            return $this->error('Coaching product not found.', 404);
        }
        $slots = $product->slots()->with('purchase.buyer:id,name,email')->where('starts_at', '>', now()->subDays(30))->get()
            ->map(fn (CreatorSlot $slot) => [
                'id' => $slot->id,
                'starts_at' => $slot->starts_at->toISOString(),
                'status' => $slot->booked_at ? 'booked' : ($slot->held_until?->isFuture() ? 'held' : ($slot->starts_at->isPast() ? 'past' : 'open')),
                'name' => $slot->booked_at ? ($slot->purchase?->buyer?->name ?: 'A buyer') : null,
                'email' => $slot->booked_at ? $slot->purchase?->buyer?->email : null,
            ]);
        // Paid sessions whose time went to someone else while the payment finished.
        $waiting = $product->purchases()->with('buyer:id,name,email')->where('status', 'paid')->whereNull('slot_id')->get()
            ->map(fn (CreatorPurchase $p) => ['name' => $p->buyer?->name ?: 'A buyer', 'email' => $p->buyer?->email]);

        return $this->success(['slots' => $slots, 'waiting' => $waiting], 'Times retrieved.');
    }

    /** Adds one or more times the coach is free. */
    public function addSlots(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product || ! $product->isCoaching()) {
            return $this->error('Coaching product not found.', 404);
        }
        $validator = Validator::make($request->all(), [
            'starts_at' => 'required|array|min:1|max:60',
            'starts_at.*' => 'required|date|after:now',
        ], ['starts_at.*.after' => 'Times must be in the future.']);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }
        if ($product->slots()->where('starts_at', '>', now())->count() + count($request->input('starts_at')) > 500) {
            return $this->error('You can have up to 500 upcoming times. Remove some old ones first.', 422);
        }

        $added = 0;
        foreach (array_unique($request->input('starts_at')) as $time) {
            $at = \Illuminate\Support\Carbon::parse($time)->utc()->startOfMinute();
            if (! $product->slots()->where('starts_at', $at)->exists()) {
                $product->slots()->create(['starts_at' => $at]);
                $added++;
            }
        }

        return $this->success(['added' => $added], $added === 1 ? 'Time added.' : "{$added} times added.", 201);
    }

    public function deleteSlot(Request $request, int $id, int $slotId): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        $slot = $product?->slots()->whereKey($slotId)->first();
        if (! $slot) {
            return $this->error('Time not found.', 404);
        }
        if ($slot->booked_at) {
            return $this->error('Someone has booked this time. Contact them to rearrange before removing it.', 409);
        }
        if ($slot->held_until?->isFuture()) {
            return $this->error('Someone is paying for this time right now. Try again in a few minutes.', 409);
        }
        $slot->delete();

        return $this->success(null, 'Time removed.');
    }

    /** People who have joined a membership, and until when. */
    public function members(Request $request, int $id): JsonResponse
    {
        $product = $this->myProduct($request, $id);
        if (! $product || ! $product->isMembership()) {
            return $this->error('Membership not found.', 404);
        }
        $members = $product->purchases()->with('buyer:id,name,email')->where('status', 'paid')
            ->selectRaw('buyer_id, max(access_until) as member_until, min(paid_at) as joined_at, count(*) as months')
            ->groupBy('buyer_id')->get()
            ->map(fn ($row) => [
                'name' => $row->buyer?->name ?: 'A member',
                'email' => $row->buyer?->email,
                'joined_at' => \Illuminate\Support\Carbon::parse($row->joined_at)->toISOString(),
                'member_until' => \Illuminate\Support\Carbon::parse($row->member_until)->toISOString(),
                'active' => \Illuminate\Support\Carbon::parse($row->member_until)->isFuture(),
                'months' => (int) $row->months,
            ])
            ->sortByDesc('member_until')->values();

        return $this->success([
            'members' => $members,
            'active' => $members->where('active', true)->count(),
        ], 'Members retrieved.');
    }

    public function sales(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->success(['sales' => []], 'No store yet.');
        }
        $sales = CreatorPurchase::with(['product:id,title', 'buyer:id,name,email'])
            ->where('store_id', $store->id)
            ->where('status', 'paid')
            ->latest('paid_at')
            ->paginate(min(100, $request->integer('items_per_page', 30)));

        $sales->getCollection()->transform(fn (CreatorPurchase $sale) => [
            'id' => $sale->id,
            'product' => $sale->product?->title,
            'buyer' => $sale->buyer?->name ?: 'A buyer',
            'price' => (float) $sale->price,
            'fee' => (float) $sale->fee,
            'earning' => (float) $sale->creator_earning,
            'fee_payer' => $sale->fee_payer,
            'currency' => $sale->currency,
            'paid_at' => $sale->paid_at?->toISOString(),
        ]);

        return $this->success(['sales' => $sales], 'Sales retrieved.');
    }

    private function deleteFiles(array $keys): void
    {
        $keys = array_values(array_filter($keys));
        if (! $keys) {
            return;
        }
        try {
            Storage::disk('s3')->delete($keys);
        } catch (\Throwable $e) {
            Log::warning('Creator file cleanup failed: ' . $e->getMessage());
        }
    }
}
