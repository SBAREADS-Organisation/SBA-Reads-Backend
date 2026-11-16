<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Staff oversight of SBA Creators: stores, their products and what SBA has earned. */
class AdminCreatorsController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $paid = CreatorPurchase::where('status', 'paid');

        $sales = CreatorPurchase::where('status', 'paid')
            ->selectRaw('store_id, count(*) filter (where price > 0) as sales, count(distinct buyer_id) as students, coalesce(sum(amount_paid), 0) as gross, coalesce(sum(fee), 0) as fees, coalesce(sum(creator_earning), 0) as earnings')
            ->groupBy('store_id')
            ->get()->keyBy('store_id');

        $stores = CreatorStore::with('user:id,name,email,kyc_status')
            ->withCount(['products', 'products as published_count' => fn ($q) => $q->where('status', 'published')])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . mb_strtolower($request->input('q')) . '%';
                $q->where(fn ($inner) => $inner->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(email) LIKE ?', [$term])));
            })
            ->latest()
            ->paginate(min(100, $request->integer('items_per_page', 50)));

        $stores->getCollection()->transform(function (CreatorStore $store) use ($sales) {
            $row = $sales[$store->id] ?? null;

            return [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'owner' => $store->user ? ['id' => $store->user->id, 'name' => $store->user->name, 'email' => $store->user->email, 'kyc_status' => $store->user->kyc_status] : null,
                'plan' => $store->activePlan(),
                'on_trial' => $store->onTrial(),
                'plan_paid_until' => $store->plan_paid_until?->toISOString(),
                'trial_ends_at' => $store->trial_ends_at?->toISOString(),
                'suspended' => $store->suspended,
                'products' => $store->products_count,
                'published' => $store->published_count,
                'sales' => (int) ($row->sales ?? 0),
                'students' => (int) ($row->students ?? 0),
                'gross' => round((float) ($row->gross ?? 0), 2),
                'fees' => round((float) ($row->fees ?? 0), 2),
                'creator_earnings' => round((float) ($row->earnings ?? 0), 2),
                'created_at' => $store->created_at?->toISOString(),
            ];
        });

        $planIncome = (float) DB::table('transactions')
            ->where('purpose_type', 'creator_plan')->whereIn('status', ['succeeded', 'success'])->sum('amount');

        return $this->success([
            'totals' => [
                'stores' => CreatorStore::count(),
                'products' => CreatorProduct::where('status', 'published')->count(),
                'sales' => (clone $paid)->where('price', '>', 0)->count(),
                'gross' => round((float) (clone $paid)->sum('amount_paid'), 2),
                'sale_fees' => round((float) (clone $paid)->sum('fee'), 2),
                'plan_income' => round($planIncome, 2),
                'currency' => 'NGN',
            ],
            'stores' => $stores,
        ], 'Creators retrieved.');
    }

    public function products(int $id): JsonResponse
    {
        $store = CreatorStore::find($id);
        if (! $store) {
            return $this->error('Store not found.', 404);
        }

        $products = $store->products()->withCount(['lessons', 'purchases as sales' => fn ($q) => $q->where('status', 'paid')])->latest()->get()
            ->map(fn (CreatorProduct $p) => [
                'id' => $p->id, 'title' => $p->title, 'slug' => $p->slug, 'type' => $p->type, 'status' => $p->status,
                'price' => (float) $p->price, 'lessons' => $p->lessons_count, 'sales' => $p->sales, 'cover_url' => $p->cover_url,
                'published_at' => $p->published_at?->toISOString(),
            ]);

        return $this->success(['store' => ['id' => $store->id, 'name' => $store->name, 'slug' => $store->slug], 'products' => $products], 'Products retrieved.');
    }

    /** Pausing a store hides it and its products from buyers; people who bought keep access. */
    public function setSuspended(Request $request, int $id): JsonResponse
    {
        $store = CreatorStore::find($id);
        if (! $store) {
            return $this->error('Store not found.', 404);
        }
        $validator = Validator::make($request->all(), ['suspended' => 'required|boolean']);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }
        $store->update(['suspended' => $request->boolean('suspended')]);

        return $this->success(['suspended' => $store->suspended], $store->suspended ? 'Store paused.' : 'Store restored.');
    }

    /** Takes a single product off sale, for example after a complaint. Buyers keep access. */
    public function hideProduct(int $id): JsonResponse
    {
        $product = CreatorProduct::find($id);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }
        $product->update(['status' => 'hidden']);

        return $this->success(null, 'Product hidden.');
    }
}
