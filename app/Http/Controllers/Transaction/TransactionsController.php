<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
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
                    $q->where('transaction_id', 'like', '%'.$request->search.'%')
                        ->orWhere('user_name', 'like', '%'.$request->search.'%')
                        ->orWhere('email', 'like', '%'.$request->search.'%');
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
                return $this->error('No transactions found for this user', 404);
            }

            return $this->success($transactions, 'Your transactions retrieved successfully');
        } catch (\Throwable $th) {
            // Handle database errors or other exceptions
            if ($th instanceof \Illuminate\Database\QueryException) {
                return $this->error('Database error: '.$th->getMessage(), 500, null, $th);
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
                    $q->where('transaction_id', 'like', '%'.$request->search.'%')
                        ->orWhere('user_name', 'like', '%'.$request->search.'%')
                        ->orWhere('email', 'like', '%'.$request->search.'%');
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
}
