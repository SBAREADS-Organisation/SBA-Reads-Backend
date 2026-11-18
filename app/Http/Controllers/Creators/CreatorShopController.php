<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorAffiliate;
use App\Models\CreatorDiscount;
use App\Models\CreatorLesson;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSlot;
use App\Models\CreatorStore;
use App\Services\Creators\CreatorPresenter;
use App\Services\Creators\CreatorService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Browsing, buying and using SBA Creators products: courses, downloads, events, coaching and memberships. */
class CreatorShopController extends Controller
{
    /** How long a coaching time is kept for someone while they pay. */
    private const HOLD_MINUTES = 30;

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
        if ($product->isOneOff() && $product->purchasedBy($user)) {
            return $this->error($product->isEvent() ? 'You already have a ticket.' : 'You already have this.', 409);
        }
        if ($this->missedInstalment($product, $user)) {
            return $this->error('You have a missed instalment for this course. Pay it from My Courses to unlock it again.', 409);
        }
        if ($product->ticketSalesClosed()) {
            return $this->error('This event has already taken place.', 422);
        }
        if ($product->spotsLeft() === 0) {
            return $this->error('Sorry, this event is sold out.', 422);
        }
        $slot = null;
        if ($product->isCoaching()) {
            $slot = CreatorSlot::where('product_id', $product->id)->whereKey($request->integer('slot_id'))->first();
            if (! $slot) {
                return $this->error('Choose a time for your session.', 422);
            }
            if (! $slot->isOpenFor($this->pendingPurchase($product, $user))) {
                return $this->error('That time has just been taken. Please choose another.', 409);
            }
        }

        [$discount, $affiliate, $problem] = $this->extras($request, $product, $user);
        if ($problem) {
            return $this->error($problem, 422);
        }
        $quote = $this->service->quote($product, $discount, $affiliate);

        // Pay in instalments: courses only, at or above the minimum, card or link for the later payments.
        $instalments = $request->integer('instalments');
        if ($instalments) {
            if (! $product->allowsInstalments() || $instalments < 2 || $instalments > $product->instalments_max || $quote['price'] < (float) config('creators.instalment_min_ngn')) {
                return $this->error('This course can\'t be paid in that many instalments.', 422);
            }
            if ($request->boolean('upsell')) {
                return $this->error('Add-ons can\'t be combined with instalments. Buy the add-on separately.', 422);
            }
        }

        // The checkout offer: another product at a special price, paid in the same payment.
        $upsell = null;
        if ($request->boolean('upsell') && ($offer = $product->activeUpsell()) && ! $offer->purchasedBy($user) && $quote['price'] > 0) {
            $upsell = [$offer, $this->service->quote($offer, null, $affiliate, (float) $product->upsell_price)];
        }

        // Free, or made free by a 100% discount code.
        if ($quote['price'] <= 0) {
            // The product row stays locked until the purchase is saved, so two people can't take the last place or the same time.
            $refusal = DB::transaction(function () use ($product, $user, $quote, $slot) {
                CreatorProduct::whereKey($product->id)->lockForUpdate()->first();
                if ($product->spotsLeft() === 0) {
                    return 'Sorry, this event is sold out.';
                }
                if ($slot && ! CreatorSlot::whereKey($slot->id)->lockForUpdate()->first()->isOpenFor()) {
                    return 'That time has just been taken. Please choose another.';
                }
                $purchase = CreatorPurchase::create([
                    'product_id' => $product->id, 'store_id' => $product->store_id, 'buyer_id' => $user->id,
                    'slot_id' => $slot?->id, 'currency' => $product->currency, 'status' => 'pending',
                ] + CreatorService::purchaseFields($quote));
                $this->service->fulfilPurchase($purchase);

                return null;
            });
            if ($refusal) {
                return $this->error($refusal, $slot ? 409 : 422);
            }

            return $this->success(['owned' => true], 'Added to your library.', 201);
        }

        try {
            return $this->startPayment($user, $product, $quote, $slot, [
                'instalments' => $instalments ?: null,
                'auto_renew' => $product->isMembership() && $request->boolean('auto_renew'),
                'upsell' => $upsell,
            ]);
        } catch (SlotTaken) {
            return $this->error('That time has just been taken. Please choose another.', 409);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('We could not start your payment. Please try again in a moment.', 502);
        }
    }

    /**
     * The discount code and affiliate a purchase uses: [discount, affiliate, problem].
     * A code that doesn't work is reported; a referral that doesn't apply is quietly ignored.
     */
    private function extras(Request $request, CreatorProduct $product, $user): array
    {
        $discount = null;
        if ($request->filled('code')) {
            $discount = CreatorDiscount::where('store_id', $product->store_id)
                ->where('code', strtoupper(trim((string) $request->input('code'))))->first();
            if (! $discount) {
                return [null, null, 'That discount code is not valid.'];
            }
            if ($problem = $discount->problemFor($product)) {
                return [null, null, $problem];
            }
        }

        $affiliate = null;
        if ($request->filled('ref') && $product->store->affiliate_rate) {
            $affiliate = CreatorAffiliate::where('store_id', $product->store_id)
                ->where('code', strtoupper(trim((string) $request->input('ref'))))->first();
            // Nobody earns commission on their own purchase.
            if ($affiliate && $user && (int) $affiliate->user_id === (int) $user->id) {
                $affiliate = null;
            }
        }

        return [$discount, $affiliate, null];
    }

    /** The price after a discount code, so buyers see it before paying. */
    public function quote(Request $request, int $id): JsonResponse
    {
        $product = $this->visible()->whereKey($id)->first();
        if (! $product) {
            return $this->error('This product is not available.', 404);
        }
        [$discount, $affiliate, $problem] = $this->extras($request, $product, $request->user('sanctum'));
        if ($problem) {
            return $this->error($problem, 422);
        }
        $quote = $this->service->quote($product, $discount, $affiliate);

        return $this->success([
            'list_price' => $quote['list_price'],
            'discount' => $quote['discount'],
            'amount_to_pay' => $quote['amount_paid'],
            'buyer_fee' => $quote['fee_payer'] === 'buyer' ? $quote['fee'] : 0,
            'code' => $discount?->code,
        ], $discount ? 'Code applied.' : 'Price retrieved.');
    }

    private function pendingPurchase(CreatorProduct $product, $user): ?CreatorPurchase
    {
        return CreatorPurchase::where('product_id', $product->id)->where('buyer_id', $user->id)
            ->where('status', 'pending')->whereNull('parent_purchase_id')->first();
    }

    private function missedInstalment(CreatorProduct $product, $user): ?CreatorPurchase
    {
        return CreatorPurchase::where('product_id', $product->id)->where('buyer_id', $user->id)
            ->where('status', 'paid')->where('instalment_status', 'defaulted')->first();
    }

    private function startPayment($user, CreatorProduct $product, array $quote, ?CreatorSlot $slot, array $options = []): JsonResponse
    {
        return DB::transaction(function () use ($user, $product, $quote, $slot, $options) {
            // Reuse an unpaid attempt so retries don't pile up records.
            $purchase = $this->pendingPurchase($product, $user)
                ?? new CreatorPurchase(['product_id' => $product->id, 'store_id' => $product->store_id, 'buyer_id' => $user->id, 'status' => 'pending']);
            $purchase->fill(CreatorService::purchaseFields($quote) + [
                'currency' => $product->currency, 'slot_id' => $slot?->id,
                'instalment_count' => $options['instalments'] ?? null,
                'auto_renew' => (bool) ($options['auto_renew'] ?? false),
            ])->save();

            // An earlier attempt's add-on is replaced by this attempt's choice.
            CreatorPurchase::where('parent_purchase_id', $purchase->id)->where('status', 'pending')->delete();
            $charge = $purchase->isInstalments() ? $purchase->instalmentPart((float) $quote['amount_paid'], 1) : (float) $quote['amount_paid'];
            $title = $product->title;
            if (! empty($options['upsell'])) {
                [$offer, $offerQuote] = $options['upsell'];
                CreatorPurchase::create([
                    'product_id' => $offer->id, 'store_id' => $offer->store_id, 'buyer_id' => $user->id, 'status' => 'pending',
                    'currency' => $offer->currency, 'parent_purchase_id' => $purchase->id,
                ] + CreatorService::purchaseFields($offerQuote));
                $charge = round($charge + (float) $offerQuote['amount_paid'], 2);
                $title .= ' + ' . $offer->title;
            }

            if ($slot) {
                // Keep this time for the buyer while they pay, and let go of any other time they were holding.
                $locked = CreatorSlot::whereKey($slot->id)->lockForUpdate()->first();
                if (! $locked->isOpenFor($purchase)) {
                    throw new SlotTaken();
                }
                CreatorSlot::where('purchase_id', $purchase->id)->whereNull('booked_at')->whereKeyNot($slot->id)
                    ->update(['purchase_id' => null, 'held_until' => null]);
                $locked->update(['purchase_id' => $purchase->id, 'held_until' => now()->addMinutes(self::HOLD_MINUTES)]);
            }

            $transaction = app(PaymentService::class)->createPayment([
                'amount' => $charge,
                'currency' => $product->currency,
                'description' => 'SBA Creators: ' . $title . ($product->isMembership() ? ' (1 month)' : '')
                    . ($purchase->isInstalments() ? " (instalment 1 of {$purchase->instalment_count})" : ''),
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
                'amount' => $charge,
                'currency' => $product->currency,
            ], 'Complete your payment to get access.');
        });
    }

    /** Everything the signed-in person has bought, joined or booked. */
    public function library(Request $request): JsonResponse
    {
        $user = $request->user();
        $products = CreatorProduct::with('store')->withCount('lessons')
            ->whereHas('purchases', fn ($q) => $q->where('buyer_id', $user->id)->where('status', 'paid'))
            ->get();

        $done = DB::table('creator_lesson_progress')
            ->join('creator_lessons', 'creator_lessons.id', '=', 'creator_lesson_progress.lesson_id')
            ->where('creator_lesson_progress.user_id', $user->id)
            ->whereNotNull('completed_at')
            ->selectRaw('creator_lessons.product_id, count(*) as done')
            ->groupBy('creator_lessons.product_id')
            ->pluck('done', 'product_id');

        return $this->success([
            'products' => $products->map(function (CreatorProduct $p) use ($done, $user) {
                $extra = ['completed_lessons' => (int) ($done[$p->id] ?? 0)];
                if ($p->isMembership()) {
                    $extra['member_until'] = $p->memberUntil($user)?->toISOString();
                }
                if ($p->isCoaching()) {
                    $next = CreatorSlot::whereIn('purchase_id', $p->purchases()->where('buyer_id', $user->id)->where('status', 'paid')->pluck('id'))
                        ->whereNotNull('booked_at')->where('starts_at', '>', now()->subHours(3))->min('starts_at');
                    $extra['next_session'] = $next ? \Illuminate\Support\Carbon::parse($next)->toISOString() : null;
                }

                return $this->presenter->product($p) + $extra;
            })->values(),
        ], 'Library retrieved.');
    }

    /** What the person gets: lessons, a ticket, their bookings, or the members' area. */
    public function content(Request $request, int $id): JsonResponse
    {
        $product = CreatorProduct::with(['store', 'lessons'])->find($id);
        $user = $request->user();
        $hasAccess = $product && ($product->ownedBy($user) || $product->purchasedBy($user));
        if (! $product || (! $hasAccess && $product->status !== 'published')) {
            return $this->error('Product not found.', 404);
        }

        if ($product->isEvent()) {
            $ticket = $hasAccess ? $product->purchases()->where('buyer_id', $user->id)->where('status', 'paid')->first() : null;

            return $this->success([
                'product' => $this->presenter->product($product, withAccess: $hasAccess),
                'has_access' => $hasAccess,
                'ticket' => $ticket ? ['code' => $ticket->ticket_code, 'checked_in_at' => $ticket->checked_in_at?->toISOString()] : null,
                'lessons' => [],
            ], 'Ticket retrieved.');
        }

        if ($product->isCoaching()) {
            $bookings = $product->purchases()->with('slot')->where('buyer_id', $user->id)->where('status', 'paid')->latest('paid_at')->get()
                ->map(fn (CreatorPurchase $p) => [
                    'id' => $p->id,
                    'starts_at' => $p->slot?->starts_at?->toISOString(),
                    'paid_at' => $p->paid_at?->toISOString(),
                ])
                ->sortBy(fn ($b) => $b['starts_at'] ?? '0')->values();

            return $this->success([
                'product' => $this->presenter->product($product, withAccess: $hasAccess),
                'has_access' => $hasAccess,
                'bookings' => $bookings,
                'lessons' => [],
            ], 'Bookings retrieved.');
        }

        $progress = DB::table('creator_lesson_progress')->where('user_id', $user->id)
            ->whereIn('lesson_id', $product->lessons->pluck('id'))
            ->get()->keyBy('lesson_id');

        return $this->success([
            'product' => $this->presenter->product($product),
            'has_access' => $hasAccess,
            'member_until' => $product->memberUntil($user)?->toISOString(),
            'renewal' => $this->renewalInfo($product, $user),
            'instalments' => $this->instalmentInfo($product, $user),
            'lessons' => $product->lessons->map(function (CreatorLesson $lesson) use ($hasAccess, $progress) {
                $row = $progress[$lesson->id] ?? null;

                return $this->presenter->lesson($lesson, $hasAccess || $lesson->is_preview) + [
                    'completed' => (bool) $row?->completed_at,
                    'position_seconds' => (int) ($row->position_seconds ?? 0),
                ];
            })->values(),
        ], 'Content retrieved.');
    }

    /** Chooses a time for a paid coaching session that doesn't have one yet. */
    public function book(Request $request, int $purchaseId): JsonResponse
    {
        $purchase = CreatorPurchase::with('product')->where('buyer_id', $request->user()->id)->where('status', 'paid')->find($purchaseId);
        if (! $purchase || ! $purchase->product->isCoaching()) {
            return $this->error('Booking not found.', 404);
        }
        if ($reason = $this->service->bookSlot($purchase, $request->integer('slot_id'))) {
            return $this->error($reason, 409);
        }

        return $this->success(null, 'Your session is booked.');
    }

    /** A member's automatic renewal setting and the card it uses. */
    private function renewalInfo(CreatorProduct $product, $user): ?array
    {
        if (! $product->isMembership() || ! $user) {
            return null;
        }
        $latest = CreatorPurchase::with('card')->where('product_id', $product->id)->where('buyer_id', $user->id)
            ->where('status', 'paid')->whereNotNull('access_until')->orderByDesc('access_until')->first();

        return $latest ? [
            'auto_renew' => (bool) $latest->auto_renew,
            'card' => $latest->card?->label(),
            'failed' => (bool) $latest->renewal_failed_at,
        ] : null;
    }

    /** Where a buyer paying in instalments is up to, and what's next. */
    private function instalmentInfo(CreatorProduct $product, $user): ?array
    {
        $purchase = $user ? CreatorPurchase::with('card')->where('product_id', $product->id)->where('buyer_id', $user->id)
            ->where('status', 'paid')->whereNotNull('instalment_count')->latest('paid_at')->first() : null;
        if (! $purchase || ! $purchase->isInstalments()) {
            return null;
        }
        $next = $purchase->instalments_paid + 1;

        return [
            'purchase_id' => $purchase->id,
            'count' => $purchase->instalment_count,
            'paid' => $purchase->instalments_paid,
            'status' => $purchase->instalment_status,
            'next_amount' => $purchase->instalment_status === 'completed' ? null : $purchase->instalmentPart((float) $purchase->amount_paid, $next),
            'next_due_at' => $purchase->next_instalment_at?->toISOString(),
            'card' => $purchase->card?->label(),
        ];
    }

    /** Pays the next instalment now with Paystack (when it's due, missed, or the buyer wants to pay early). */
    public function payInstalment(Request $request, int $purchaseId): JsonResponse
    {
        $purchase = CreatorPurchase::with(['product', 'buyer'])->where('buyer_id', $request->user()->id)
            ->where('status', 'paid')->find($purchaseId);
        if (! $purchase || ! $purchase->isInstalments() || $purchase->instalment_status === 'completed') {
            return $this->error('There is nothing left to pay on this.', 404);
        }
        try {
            return $this->success($this->service->instalmentPaymentLink($purchase), 'Complete your payment.');
        } catch (\Throwable $e) {
            report($e);

            return $this->error('We could not start your payment. Please try again in a moment.', 502);
        }
    }

    /** Turns automatic monthly renewal on or off for the buyer's membership. */
    public function autoRenew(Request $request, int $id): JsonResponse
    {
        $request->validate(['enabled' => 'required|boolean']);
        $latest = CreatorPurchase::with('card')->where('product_id', $id)->where('buyer_id', $request->user()->id)
            ->where('status', 'paid')->whereNotNull('access_until')->orderByDesc('access_until')->first();
        if (! $latest) {
            return $this->error('Membership not found.', 404);
        }
        if ($request->boolean('enabled') && ! $latest->card_id) {
            return $this->error('Renew once with a card and tick "Renew automatically" to turn this on.', 422);
        }
        $latest->update(['auto_renew' => $request->boolean('enabled'), 'renewal_failed_at' => null, 'renewal_attempted_at' => null]);

        return $this->success(['auto_renew' => $latest->auto_renew], $latest->auto_renew
            ? 'Your membership will renew automatically.'
            : 'Automatic renewal is off. Your membership ends on ' . $latest->access_until->format('j F Y') . '.');
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

/** Thrown when a coaching time is taken between choosing it and paying. */
class SlotTaken extends \RuntimeException
{
}
