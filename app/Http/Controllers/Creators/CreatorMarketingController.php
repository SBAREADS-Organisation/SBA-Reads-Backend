<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorAffiliate;
use App\Models\CreatorBroadcast;
use App\Models\CreatorDiscount;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorStore;
use App\Models\CreatorSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** SBA Creators marketing: discount codes, affiliates and email lists. */
class CreatorMarketingController extends Controller
{
    /** Broadcast emails a store can send in a day, so lists aren't flooded. */
    private const BROADCASTS_PER_DAY = 1;

    private function myStore(Request $request): ?CreatorStore
    {
        return CreatorStore::where('user_id', $request->user()->id)->first();
    }

    private function noStore(): JsonResponse
    {
        return $this->error('Create your store first.', 404);
    }

    private function site(): string
    {
        return rtrim((string) config('creators.website_url'), '/');
    }

    // ---------- Discount codes ----------

    public function discounts(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->noStore();
        }
        $discounts = CreatorDiscount::with('product:id,title')->where('store_id', $store->id)->latest()->get()
            ->map(fn (CreatorDiscount $d) => $this->discount($d));

        return $this->success(['discounts' => $discounts], 'Discount codes retrieved.');
    }

    private function discount(CreatorDiscount $d): array
    {
        return [
            'id' => $d->id,
            'code' => $d->code,
            'kind' => $d->kind,
            'value' => (float) $d->value,
            'product' => $d->product ? ['id' => $d->product->id, 'title' => $d->product->title] : null,
            'max_uses' => $d->max_uses,
            'uses' => $d->uses,
            'expires_at' => $d->expires_at?->toISOString(),
            'active' => $d->active,
        ];
    }

    public function createDiscount(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->noStore();
        }
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('creator_discounts', 'code')->where('store_id', $store->id)],
            'kind' => 'required|in:percent,amount',
            'value' => ['required', 'numeric', 'min:1', $request->input('kind') === 'percent' ? 'max:100' : 'max:' . config('creators.max_price_ngn')],
            'product_id' => ['nullable', 'integer', Rule::exists('creator_products', 'id')->where('store_id', $store->id)],
            'max_uses' => 'nullable|integer|min:1|max:1000000',
            'expires_at' => 'nullable|date|after:now',
        ], [
            'code.regex' => 'Codes can only use letters, numbers, dashes and underscores.',
            'code.unique' => 'You already have a code with that name.',
            'value.max' => 'A percentage discount can be at most 100%.',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, $validator->errors());
        }

        $discount = CreatorDiscount::create($request->only(['code', 'kind', 'value', 'product_id', 'max_uses', 'expires_at']) + ['store_id' => $store->id]);

        return $this->success(['discount' => $this->discount($discount->load('product'))], 'Discount code created.', 201);
    }

    public function updateDiscount(Request $request, int $id): JsonResponse
    {
        $store = $this->myStore($request);
        $discount = $store ? CreatorDiscount::where('store_id', $store->id)->find($id) : null;
        if (! $discount) {
            return $this->error('Discount code not found.', 404);
        }
        $request->validate(['active' => 'required|boolean']);
        $discount->update(['active' => $request->boolean('active')]);

        return $this->success(['discount' => $this->discount($discount->load('product'))], $discount->active ? 'Code turned on.' : 'Code turned off.');
    }

    public function deleteDiscount(Request $request, int $id): JsonResponse
    {
        $store = $this->myStore($request);
        $discount = $store ? CreatorDiscount::where('store_id', $store->id)->find($id) : null;
        if (! $discount) {
            return $this->error('Discount code not found.', 404);
        }
        // Codes that have been used are kept for the sales record and simply switched off.
        if ($discount->uses > 0) {
            $discount->update(['active' => false]);

            return $this->success(null, 'This code has been used, so it has been turned off instead of deleted.');
        }
        $discount->delete();

        return $this->success(null, 'Discount code deleted.');
    }

    // ---------- Affiliates (creator side) ----------

    public function affiliates(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->noStore();
        }
        $sales = CreatorPurchase::where('store_id', $store->id)->where('status', 'paid')->whereNotNull('affiliate_id')
            ->selectRaw('affiliate_id, count(*) as sales, sum(affiliate_earning) as earned, sum(price) as revenue')
            ->groupBy('affiliate_id')->get()->keyBy('affiliate_id');
        $affiliates = CreatorAffiliate::with('user:id,name,email')->where('store_id', $store->id)->latest()->get()
            ->map(fn (CreatorAffiliate $a) => [
                'id' => $a->id,
                'name' => $a->user?->name ?: 'An affiliate',
                'email' => $a->user?->email,
                'code' => $a->code,
                'joined_at' => $a->created_at?->toISOString(),
                'sales' => (int) ($sales[$a->id]->sales ?? 0),
                'revenue' => round((float) ($sales[$a->id]->revenue ?? 0), 2),
                'earned' => round((float) ($sales[$a->id]->earned ?? 0), 2),
            ])
            ->sortByDesc('sales')->values();

        return $this->success([
            'affiliate_rate' => $store->affiliate_rate !== null ? (float) $store->affiliate_rate : null,
            'join_link' => $this->site() . "/creators/{$store->slug}?affiliate=1",
            'affiliates' => $affiliates,
        ], 'Affiliates retrieved.');
    }

    /** Turns the affiliate programme on with a commission, or off (rate null). */
    public function affiliateSettings(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->noStore();
        }
        $validator = Validator::make($request->all(), ['rate' => 'nullable|numeric|min:0.05|max:0.7'], [
            'rate.min' => 'Commission must be at least 5%.',
            'rate.max' => 'Commission can be at most 70%.',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, $validator->errors());
        }
        $store->update(['affiliate_rate' => $request->input('rate')]);

        return $this->success(
            ['affiliate_rate' => $store->affiliate_rate !== null ? (float) $store->affiliate_rate : null],
            $store->affiliate_rate ? 'Affiliates turned on.' : 'Affiliates turned off. Existing links stop earning commission.'
        );
    }

    // ---------- Affiliates (affiliate side) ----------

    /** Anyone signed in can become an affiliate of a store with the programme on. */
    public function joinAffiliate(Request $request, string $slug): JsonResponse
    {
        $store = CreatorStore::where('slug', $slug)->where('suspended', false)->first();
        if (! $store) {
            return $this->error('Store not found.', 404);
        }
        if (! $store->affiliate_rate) {
            return $this->error('This store is not looking for affiliates right now.', 422);
        }
        if ((int) $store->user_id === (int) $request->user()->id) {
            return $this->error('This is your own store.', 409);
        }

        $affiliate = CreatorAffiliate::firstOrCreate(
            ['store_id' => $store->id, 'user_id' => $request->user()->id],
            ['code' => $this->newAffiliateCode()],
        );

        return $this->success(['affiliate' => $this->affiliateLinks($affiliate->load('store'))], 'You are now an affiliate of ' . $store->name . '.', $affiliate->wasRecentlyCreated ? 201 : 200);
    }

    /** The signed-in person's affiliate links and what they've earned. */
    public function myAffiliates(Request $request): JsonResponse
    {
        $mine = CreatorAffiliate::with('store')->where('user_id', $request->user()->id)->latest()->get();
        $sales = CreatorPurchase::whereIn('affiliate_id', $mine->pluck('id'))->where('status', 'paid')
            ->selectRaw('affiliate_id, count(*) as sales, sum(affiliate_earning) as earned')
            ->groupBy('affiliate_id')->get()->keyBy('affiliate_id');

        return $this->success([
            'affiliates' => $mine->map(fn (CreatorAffiliate $a) => $this->affiliateLinks($a) + [
                'sales' => (int) ($sales[$a->id]->sales ?? 0),
                'earned' => round((float) ($sales[$a->id]->earned ?? 0), 2),
            ])->values(),
        ], 'Affiliate links retrieved.');
    }

    private function affiliateLinks(CreatorAffiliate $affiliate): array
    {
        $store = $affiliate->store;

        return [
            'id' => $affiliate->id,
            'code' => $affiliate->code,
            'store' => ['name' => $store->name, 'slug' => $store->slug],
            'rate' => $store->affiliate_rate !== null ? (float) $store->affiliate_rate : null,
            'link' => $this->site() . "/creators/{$store->slug}?ref={$affiliate->code}",
            'products' => CreatorProduct::where('store_id', $store->id)->where('status', 'published')->latest('published_at')->get(['id', 'title', 'slug', 'price', 'type'])
                ->map(fn ($p) => [
                    'title' => $p->title,
                    'price' => (float) $p->price,
                    'type' => $p->type,
                    'link' => $this->site() . "/creators/{$store->slug}/{$p->slug}?ref={$affiliate->code}",
                ])->values(),
        ];
    }

    private function newAffiliateCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (CreatorAffiliate::where('code', $code)->exists());

        return $code;
    }

    // ---------- Email list ----------

    /** Visitors join a store's email list from the store page. */
    public function subscribe(Request $request, string $slug): JsonResponse
    {
        $store = CreatorStore::where('slug', $slug)->where('suspended', false)->first();
        if (! $store) {
            return $this->error('Store not found.', 404);
        }
        $validator = Validator::make($request->all(), ['email' => 'required|email:rfc|max:255', 'name' => 'nullable|string|max:120']);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, $validator->errors());
        }
        CreatorSubscriber::add($store->id, $request->input('email'), $request->input('name'), 'signup');

        return $this->success(null, "You're on {$store->name}'s list. You'll hear about new products and news.");
    }

    public function unsubscribe(string $token): JsonResponse
    {
        $subscriber = CreatorSubscriber::where('token', $token)->first();
        if (! $subscriber) {
            return $this->error('This link is not valid.', 404);
        }
        if (! $subscriber->unsubscribed_at) {
            $subscriber->update(['unsubscribed_at' => now()]);
        }
        $store = CreatorStore::find($subscriber->store_id);

        return $this->success(['store' => $store?->name], "You won't get any more emails from " . ($store?->name ?? 'this creator') . '.');
    }

    public function subscribers(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->noStore();
        }
        $all = CreatorSubscriber::where('store_id', $store->id);
        $page = (clone $all)->latest()->paginate(min(200, $request->integer('items_per_page', 50)));
        $page->getCollection()->transform(fn (CreatorSubscriber $s) => [
            'email' => $s->email,
            'name' => $s->name,
            'source' => $s->source,
            'subscribed' => ! $s->unsubscribed_at,
            'joined_at' => $s->created_at?->toISOString(),
        ]);

        return $this->success([
            'subscribers' => $page,
            'active' => (clone $all)->whereNull('unsubscribed_at')->count(),
            'unsubscribed' => (clone $all)->whereNotNull('unsubscribed_at')->count(),
            'broadcasts' => CreatorBroadcast::where('store_id', $store->id)->latest()->limit(20)->get(['id', 'subject', 'recipients', 'created_at']),
            'can_send_at' => $this->nextSendAt($store)?->toISOString(),
            'signup_link' => $this->site() . "/creators/{$store->slug}",
        ], 'Email list retrieved.');
    }

    /** Downloads the list as a CSV file. */
    public function exportSubscribers(Request $request)
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->noStore();
        }
        $rows = CreatorSubscriber::where('store_id', $store->id)->orderBy('created_at')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'source', 'subscribed', 'joined']);
            foreach ($rows as $s) {
                fputcsv($out, [$s->email, $s->name, $s->source, $s->unsubscribed_at ? 'no' : 'yes', $s->created_at?->toDateString()]);
            }
            fclose($out);
        }, Str::slug($store->name) . '-email-list.csv', ['Content-Type' => 'text/csv']);
    }

    private function nextSendAt(CreatorStore $store): ?\Illuminate\Support\Carbon
    {
        $recent = CreatorBroadcast::where('store_id', $store->id)->where('created_at', '>', now()->subDay())->orderBy('created_at')->get();

        return $recent->count() >= self::BROADCASTS_PER_DAY ? $recent->first()->created_at->copy()->addDay() : null;
    }

    /** Emails everyone on the list. Each email carries that person's unsubscribe link. */
    public function broadcast(Request $request): JsonResponse
    {
        $store = $this->myStore($request);
        if (! $store) {
            return $this->noStore();
        }
        if ($store->suspended) {
            return $this->error('Your store is paused. Please contact admin@sbareads.com.', 403);
        }
        $validator = Validator::make($request->all(), ['subject' => 'required|string|max:160', 'body' => 'required|string|max:20000']);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, $validator->errors());
        }
        if ($next = $this->nextSendAt($store)) {
            return $this->error('You can send one email a day. Your next one can go out at ' . $next->format('g:ia \o\n j M') . '.', 429);
        }
        $subscribers = CreatorSubscriber::where('store_id', $store->id)->whereNull('unsubscribed_at')->get();
        if ($subscribers->isEmpty()) {
            return $this->error('Your list is empty. People join when they buy from you or sign up on your store page.', 422);
        }

        $broadcast = CreatorBroadcast::create([
            'store_id' => $store->id, 'subject' => $request->input('subject'), 'body' => $request->input('body'), 'recipients' => $subscribers->count(),
        ]);
        foreach ($subscribers as $subscriber) {
            Mail::to($subscriber->email)->queue(new GenericAppNotification(
                $request->input('subject'),
                $request->input('body')
                . "\n\n—\n{$store->name} on SBA Reads: " . $this->site() . "/creators/{$store->slug}"
                . "\nYou're getting this because you bought from or signed up to {$store->name}. Unsubscribe: "
                . $this->site() . "/creators/unsubscribe/{$subscriber->token}"
            ));
        }

        return $this->success(['recipients' => $broadcast->recipients], "Sending to {$broadcast->recipients} people.");
    }
}
