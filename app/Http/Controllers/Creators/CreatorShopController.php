<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorLesson;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorStore;
use App\Services\Creators\CreatorPresenter;
use App\Services\Creators\CreatorService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Browsing, buying and taking SBA Creators courses and downloads. */
class CreatorShopController extends Controller
{
    public function __construct(private CreatorPresenter $presenter, private CreatorService $service)
    {
    }

    private function visible()
    {
        return CreatorProduct::with('store')
            ->withCount('lessons')
            ->where('status', 'published')
            ->whereHas('store', fn ($q) => $q->where('suspended', false));
    }

    public function index(Request $request): JsonResponse
    {
        $products = $this->visible()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . mb_strtolower($request->input('q')) . '%';
                $q->where(fn ($inner) => $inner->whereRaw('LOWER(title) LIKE ?', [$term])->orWhereRaw('LOWER(summary) LIKE ?', [$term]));
            })
            ->latest('published_at')
            ->paginate(min(60, $request->integer('items_per_page', 24)));
        $products->getCollection()->transform(fn ($p) => $this->presenter->product($p));

        return $this->success(['products' => $products], 'Products retrieved.');
    }

    public function store(string $slug): JsonResponse
    {
        $store = CreatorStore::where('slug', $slug)->where('suspended', false)->first();
        if (! $store) {
            return $this->error('Store not found.', 404);
        }
        $products = $this->visible()->where('store_id', $store->id)->latest('published_at')->get();

        return $this->success([
            'store' => $this->presenter->store($store),
            'products' => $products->map(fn ($p) => $this->presenter->product($p))->values(),
        ], 'Store retrieved.');
    }

    public function product(Request $request, string $slug, string $productSlug): JsonResponse
    {
        $product = $this->visible()->whereHas('store', fn ($q) => $q->where('slug', $slug))->where('slug', $productSlug)->first();
        if (! $product) {
            return $this->error('Product not found.', 404);
        }
        $viewer = $request->user('sanctum');

        return $this->success([
            'product' => $this->presenter->product($product, withLessons: true),
            'owned' => $product->purchasedBy($viewer) || $product->ownedBy($viewer),
        ], 'Product retrieved.');
    }

    /** Free products are added straight away; paid ones start a payment. */
    public function buy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $product = $this->visible()->whereKey($id)->first();
        if (! $product) {
            return $this->error('This product is not available.', 404);
        }
        if ($product->ownedBy($user)) {
            return $this->error('This is your own product.', 409);
        }
        if ($product->purchasedBy($user)) {
            return $this->error('You already have this.', 409);
        }

        $quote = $this->service->quote($product);

        if ($product->isFree()) {
            $purchase = CreatorPurchase::create([
                'product_id' => $product->id, 'store_id' => $product->store_id, 'buyer_id' => $user->id,
                'price' => 0, 'fee' => 0, 'amount_paid' => 0, 'creator_earning' => 0,
                'fee_payer' => $quote['fee_payer'], 'plan' => $quote['plan'], 'currency' => $product->currency, 'status' => 'pending',
            ]);
            $this->service->fulfilPurchase($purchase);

            return $this->success(['owned' => true], 'Added to your library.', 201);
        }

        try {
            return $this->startPayment($user, $product, $quote);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('We could not start your payment. Please try again in a moment.', 502);
        }
    }

    private function startPayment($user, CreatorProduct $product, array $quote): JsonResponse
    {
        return DB::transaction(function () use ($user, $product, $quote) {
            // Reuse an unpaid attempt so retries don't pile up records.
            $purchase = CreatorPurchase::where('product_id', $product->id)->where('buyer_id', $user->id)->where('status', 'pending')->first()
                ?? new CreatorPurchase(['product_id' => $product->id, 'store_id' => $product->store_id, 'buyer_id' => $user->id, 'status' => 'pending']);
            $purchase->fill([
                'price' => $quote['price'], 'fee' => $quote['fee'], 'amount_paid' => $quote['amount_paid'],
                'creator_earning' => $quote['creator_earning'], 'fee_payer' => $quote['fee_payer'],
                'plan' => $quote['plan'], 'currency' => $product->currency,
            ])->save();

            $transaction = app(PaymentService::class)->createPayment([
                'amount' => $quote['amount_paid'],
                'currency' => $product->currency,
                'description' => 'SBA Creators: ' . $product->title,
                'purpose' => 'creator_purchase',
                'purpose_id' => $purchase->id,
                'meta_data' => ['product_id' => $product->id, 'purchase_id' => $purchase->id],
            ], $user);

            if ($transaction instanceof JsonResponse) {
                throw new \RuntimeException('Payment could not be started.');
            }

            return $this->success([
                'authorization_url' => $transaction->payment_client_secret,
                'reference' => $transaction->reference,
                'amount' => $quote['amount_paid'],
                'currency' => $product->currency,
            ], 'Complete your payment to get access.');
        });
    }

    /** Everything the signed-in person has bought or claimed. */
    public function library(Request $request): JsonResponse
    {
        $products = CreatorProduct::with('store')->withCount('lessons')
            ->whereHas('purchases', fn ($q) => $q->where('buyer_id', $request->user()->id)->where('status', 'paid'))
            ->get();

        $done = DB::table('creator_lesson_progress')
            ->join('creator_lessons', 'creator_lessons.id', '=', 'creator_lesson_progress.lesson_id')
            ->where('creator_lesson_progress.user_id', $request->user()->id)
            ->whereNotNull('completed_at')
            ->selectRaw('creator_lessons.product_id, count(*) as done')
            ->groupBy('creator_lessons.product_id')
            ->pluck('done', 'product_id');

        return $this->success([
            'products' => $products->map(fn ($p) => $this->presenter->product($p) + [
                'completed_lessons' => (int) ($done[$p->id] ?? 0),
            ])->values(),
        ], 'Library retrieved.');
    }

    /** Lessons with their content. Buyers and the creator see everything; anyone sees preview lessons. */
    public function content(Request $request, int $id): JsonResponse
    {
        $product = CreatorProduct::with(['store', 'lessons'])->find($id);
        $user = $request->user();
        $hasAccess = $product && ($product->ownedBy($user) || $product->purchasedBy($user));
        if (! $product || (! $hasAccess && $product->status !== 'published')) {
            return $this->error('Product not found.', 404);
        }

        $progress = DB::table('creator_lesson_progress')->where('user_id', $user->id)
            ->whereIn('lesson_id', $product->lessons->pluck('id'))
            ->get()->keyBy('lesson_id');

        return $this->success([
            'product' => $this->presenter->product($product),
            'has_access' => $hasAccess,
            'lessons' => $product->lessons->map(function (CreatorLesson $lesson) use ($hasAccess, $progress) {
                $row = $progress[$lesson->id] ?? null;

                return $this->presenter->lesson($lesson, $hasAccess || $lesson->is_preview) + [
                    'completed' => (bool) $row?->completed_at,
                    'position_seconds' => (int) ($row->position_seconds ?? 0),
                ];
            })->values(),
        ], 'Content retrieved.');
    }

    public function progress(Request $request, int $lessonId): JsonResponse
    {
        $lesson = CreatorLesson::with('product')->find($lessonId);
        $user = $request->user();
        if (! $lesson || ! ($lesson->product->ownedBy($user) || $lesson->product->purchasedBy($user))) {
            return $this->error('Lesson not found.', 404);
        }
        $request->validate(['completed' => 'sometimes|boolean', 'position_seconds' => 'sometimes|integer|min:0']);

        $changes = ['updated_at' => now()];
        if ($request->has('completed')) {
            $changes['completed_at'] = $request->boolean('completed') ? now() : null;
        }
        if ($request->has('position_seconds')) {
            $changes['position_seconds'] = $request->integer('position_seconds');
        }
        DB::table('creator_lesson_progress')->updateOrInsert(
            ['lesson_id' => $lesson->id, 'user_id' => $user->id],
            $changes + ['created_at' => now()],
        );

        return $this->success(null, 'Progress saved.');
    }
}
