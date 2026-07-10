<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\AudioBookPurchase;
use App\Models\DigitalBookPurchase;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransactionsController extends Controller
{
    //
    protected $service;

    use ApiResponse;

    public function __construct(PaymentService $service)
    {
        $this->service = $service;
    }

    public function verifyPayment(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'payment_intent_id' => 'required_without:id|string',
                'id' => 'required_without:payment_intent_id|integer',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation Error', 400, $validator->errors());
            }

            // After verifying the transaction check if the purpose is for order or subscription and update accordingly.

            return $this->service->verifyTransaction($request->all());
        } catch (\Throwable $th) {
            return $this->error($th->getMessage(), 500, null, $th);
        }
    }

    public function getMyTransactions(Request $request)
    {
        try {
            $user = $request->user();
            $query = $this->service->getTransactionQuery()->where('user_id', $user->id);

            // Apply search filter
            if ($request->has('search') && ! empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('transaction_id', 'like', '%' . $request->search . '%')
                        ->orWhere('user_name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            // Apply date range filter
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
            }

            // Apply status filter
            if ($request->has('status') && ! empty($request->status)) {
                $query->where('status', $request->status);
            }

            // Apply sorting
            if ($request->has('sort_by') && $request->has('sort_order')) {
                $query->orderBy($request->sort_by, $request->sort_order);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Paginate results
            $transactions = $query->paginate($request->get('per_page', 15));

            if ($transactions->isEmpty()) {
                return $this->success([], 'No transactions found for this user');
            }

            return $this->success($transactions, 'Your transactions retrieved successfully');
        } catch (\Throwable $th) {
            // Handle database errors or other exceptions
            if ($th instanceof \Illuminate\Database\QueryException) {
                return $this->error('Database error: ' . $th->getMessage(), 500, null, $th);
            }

            return $this->error($th->getMessage(), 500, null, $th);
        }
    }

    public function getAllTransactions(Request $request)
    {
        try {
            $query = $this->service->getTransactionQuery();

            // Apply search filter
            if ($request->has('search') && ! empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('transaction_id', 'like', '%' . $request->search . '%')
                        ->orWhere('user_name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            // Apply date range filter — end_date is made inclusive of the full day
            // so "2026-06-07" covers 00:00:00–23:59:59 rather than just midnight.
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('created_at', [
                    $request->start_date . ' 00:00:00',
                    $request->end_date   . ' 23:59:59',
                ]);
            } elseif ($request->filled('start_date')) {
                $query->where('created_at', '>=', $request->start_date . ' 00:00:00');
            }

            // Apply status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Apply sorting
            if ($request->filled('sort_by') && $request->filled('sort_order')) {
                $query->orderBy($request->sort_by, $request->sort_order);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $transactions = $query->paginate($request->get('per_page', 50));

            $this->enrichWithBookData($transactions->getCollection());

            return $this->success($transactions, 'Transactions retrieved successfully');
        } catch (\Throwable $th) {
            return $this->error($th->getMessage(), 500, null, $th);
        }
    }

    public function getTransaction(Request $request, $id)
    {
        try {
            $transaction = $this->service->getTransactionById($id);

            if (! $transaction) {
                return $this->error('Transaction not found', 404);
            }

            return $this->success($transaction, 'Transaction retrieved successfully');
        } catch (\Throwable $th) {
            return $this->error($th->getMessage(), 500, null, $th);
        }
    }

    private function enrichWithBookData(\Illuminate\Support\Collection $collection): void
    {
        $digitalIds = $collection->where('purpose_type', 'digital_book_purchase')
            ->pluck('purpose_id')->filter()->unique()->values()->toArray();
        $audioIds   = $collection->where('purpose_type', 'audio_book_purchase')
            ->pluck('purpose_id')->filter()->unique()->values()->toArray();

        $digitalPurchases = !empty($digitalIds)
            ? DigitalBookPurchase::with('items.book:id,title')->whereIn('id', $digitalIds)->get()->keyBy('id')
            : collect();

        $audioPurchases = !empty($audioIds)
            ? AudioBookPurchase::with('book:id,title')->whereIn('id', $audioIds)->get()->keyBy('id')
            : collect();

        // Build (user_id => [book_ids]) map for a single library query
        $checkPairs = [];
        foreach ($digitalPurchases as $p) {
            foreach ($p->items->pluck('book_id')->filter() as $bookId) {
                $checkPairs[$p->user_id][] = $bookId;
            }
        }
        foreach ($audioPurchases as $p) {
            if ($p->book_id) {
                $checkPairs[$p->user_id][] = $p->book_id;
            }
        }

        $libraryMap = [];
        if (!empty($checkPairs)) {
            $allUserIds = array_keys($checkPairs);
            $allBookIds = array_unique(array_merge(...array_values($checkPairs)));
            DB::table('book_user')
                ->whereIn('user_id', $allUserIds)
                ->whereIn('book_id', $allBookIds)
                ->get(['user_id', 'book_id'])
                ->each(fn($row) => $libraryMap[$row->user_id][$row->book_id] = true);
        }

        $collection->transform(function ($txn) use ($digitalPurchases, $audioPurchases, $libraryMap) {
            $txn->book_names = [];
            $txn->in_library = null;

            if ($txn->purpose_type === 'digital_book_purchase' && $txn->purpose_id) {
                $p = $digitalPurchases->get($txn->purpose_id);
                if ($p) {
                    $bookIds         = $p->items->pluck('book_id')->filter()->toArray();
                    $txn->book_names = $p->items->pluck('book.title')->filter()->values()->toArray();
                    if ($p->user_id && !empty($bookIds)) {
                        $lib             = $libraryMap[$p->user_id] ?? [];
                        $txn->in_library = count(array_filter($bookIds, fn($id) => isset($lib[$id]))) === count($bookIds);
                    } else {
                        $txn->in_library = false;
                    }
                }
            } elseif ($txn->purpose_type === 'audio_book_purchase' && $txn->purpose_id) {
                $p = $audioPurchases->get($txn->purpose_id);
                if ($p && $p->book) {
                    $txn->book_names = [$p->book->title];
                    $txn->in_library = $p->user_id && $p->book_id
                        ? isset(($libraryMap[$p->user_id] ?? [])[$p->book_id])
                        : false;
                }
            }

            return $txn;
        });
    }

    /**
     * Get author's transaction history
     * Returns all transactions for the authenticated author including payouts and earnings
     */
    public function getAuthorTransactions(Request $request)
    {
        try {
            $user = $request->user();

            // Ensure user is an author
            if (!$user->isAuthor()) {
                return $this->error('Access denied. Only authors can access this endpoint.', 403);
            }

            $query = $this->service->getTransactionQuery()->where('user_id', $user->id);

            // Apply search filter
            if ($request->has('search') && !empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('reference', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%')
                        ->orWhere('payment_intent_id', 'like', '%' . $request->search . '%');
                });
            }

            // Apply date range filter
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
            }

            // Apply status filter
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            // Apply transaction type filter (payout, purchase, etc.)
            if ($request->has('type') && !empty($request->type)) {
                $query->where('type', $request->type);
            }

            // Apply direction filter (credit for payouts, debit for purchases)
            if ($request->has('direction') && !empty($request->direction)) {
                $query->where('direction', $request->direction);
            }

            // Apply purpose type filter (digital_book_purchase, order, etc.)
            if ($request->has('purpose_type') && !empty($request->purpose_type)) {
                $query->where('purpose_type', $request->purpose_type);
            }

            // Apply sorting
            if ($request->has('sort_by') && $request->has('sort_order')) {
                $query->orderBy($request->sort_by, $request->sort_order);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Paginate results
            $transactions = $query->paginate($request->get('per_page', 15));

            if ($transactions->isEmpty()) {
                return $this->success([], 'No transactions found for this author');
            }

            return $this->success($transactions, 'Author transactions retrieved successfully');
        } catch (\Throwable $th) {
            return $this->error($th->getMessage(), 500, null, $th);
        }
    }
}
