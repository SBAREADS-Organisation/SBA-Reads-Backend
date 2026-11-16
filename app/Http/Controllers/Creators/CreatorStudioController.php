<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorLesson;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorStore;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\Creators\CreatorPresenter;
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
            'plans' => config('creators.plans'),
            'trial_days' => config('creators.trial_days'),
        ], 'Plans retrieved.');
    }

    public function show(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->success(['store' => null, 'plans' => config('creators.plans')], 'No store yet.');
        }

        $paid = CreatorPurchase::where('store_id', $store->id)->where('status', 'paid');

        return $this->success([
            'store' => $this->presenter->store($store, true),
            'plans' => config('creators.plans'),
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
            'slug' => 'nullable|string|max:60|regex:/^[a-z0-9-]+$/|unique:creator_stores,slug',
            'bio' => 'nullable|string|max:2000',
            'fee_payer' => 'nullable|in:creator,buyer',
        ], ['slug.regex' => 'Your store link can only use lowercase letters, numbers and dashes.', 'slug.unique' => 'That store link is taken. Please choose another.']);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $store = CreatorStore::create([
            'user_id' => $user->id,
            'name' => $request->input('name'),
            'slug' => $request->input('slug') ?: $this->uniqueSlug($request->input('name'), fn ($s) => CreatorStore::where('slug', $s)->exists()),
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
            'slug' => ['sometimes', 'required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('creator_stores', 'slug')->ignore($store->id)],
            'bio' => 'sometimes|nullable|string|max:2000',
            'fee_payer' => 'sometimes|in:creator,buyer',
            'logo' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], ['slug.regex' => 'Your store link can only use lowercase letters, numbers and dashes.', 'slug.unique' => 'That store link is taken. Please choose another.']);
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
        $validator = Validator::make($request->all(), ['plan' => 'required|in:pro,business']);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $plan = $request->input('plan');
        $price = (float) config("creators.plans.{$plan}.price_ngn");
        $transaction = app(PaymentService::class)->createPayment([
            'amount' => $price,
            'currency' => 'NGN',
            'description' => 'SBA Creators ' . config("creators.plans.{$plan}.name") . ' plan (1 month)',
            'purpose' => 'creator_plan',
            'purpose_id' => $store->id,
            'meta_data' => ['store_id' => $store->id, 'plan' => $plan],
        ], $request->user());

        if ($transaction instanceof JsonResponse) {
            return $this->error('We could not start your payment. Please try again.', 502);
        }

        return $this->success([
            'authorization_url' => $transaction->payment_client_secret,
            'reference' => $transaction->reference,
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
        ];
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
        ]);
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

    private function cannotPublish(Request $request, CreatorProduct $product, float $price): ?string
    {
        if ($product->store->suspended) {
            return 'Your store is paused. Please contact admin@sbareads.com.';
        }
        if ($price > 0 && $request->user()->kyc_status !== 'verified') {
            return 'Verify your identity before selling paid products, so we can pay you. Free products can be published now.';
        }
        if (! $product->lessons()->exists()) {
            return $product->type === 'course' ? 'Add at least one lesson before publishing.' : 'Upload the file buyers will download before publishing.';
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
        if ($request->integer('size') > $rules['max_mb'] * 1024 * 1024) {
            return $this->error("That file is too large. The limit is {$rules['max_mb']} MB.", 422);
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
            return $this->error('A published product needs at least one lesson. Add another first, or unpublish the product.', 422);
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
