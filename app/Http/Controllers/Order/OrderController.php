<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Services\Orders\OrderService;
use App\Services\Stripe\StripeConnectService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    //
    use ApiResponse;

    protected OrderService $service;

    protected StripeConnectService $stripe;

    public function __construct(OrderService $service, StripeConnectService $stripe)
    {
        $this->service = $service;
        $this->stripe = $stripe;
    }

    public function index(Request $request)
    {
        try {
            $q = $request->user()->orders()->with('items.book');
            if ($request->filled('status')) {
                $q->where('status', $request->status);
            }

            // Filter by date range
            if ($request->filled('from_date')) {
                $q->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $q->whereDate('created_at', '<=', $request->to_date);
            }

            if ($request->filled('search')) {
                $q->whereHas('items.book', fn ($b) => $b->where('title', 'like', '%'.$request->search.'%')
                );
            }
            if ($request->filled('sort_by')) {
                $q->orderBy($request->sort_by, $request->get('order', 'desc'));
            }

            $pag = $q->paginate($request->get('per_page', 15));

            if ($pag->isEmpty()) {
                return $this->error('No orders found', 404);
            }

            return $this->success($pag, 'Orders retrieved');
        } catch (\Throwable $th) {
            // throw $th;
            return $this->error('An error occured while, fetching all orders. Try again!', 500, null, $th);
        }
    }

    /**
     * Get all orders for the logged-in user with advanced search and filter options.
     */
    public function userOrders(Request $request)
    {
        try {
            $query = $request->user()->orders()->with(['items.book', 'transaction', 'deliveryAddress']);

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Filter by date range
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // Search by book title or order number
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%$search%")
                        ->orWhereHas('items.book', function ($b) use ($search) {
                            $b->where('title', 'like', "%$search%");
                        });
                });
            }

            // Sort
            if ($request->filled('sort_by')) {
                $query->orderBy($request->sort_by, $request->get('order', 'desc'));
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Pagination
            $orders = $query->paginate($request->get('per_page', 15));

            if ($orders->isEmpty()) {
                return $this->error('No orders found', 404);
            }

            return $this->success($orders, 'User orders retrieved successfully');
        } catch (\Throwable $th) {
            return $this->error('An error occurred while fetching user orders. Try again!', 500, null, $th);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'books' => 'required|array|min:1',
                'books.*.book_id' => 'required|exists:books,id',
                'books.*.quantity' => 'required|integer|min:1',
                'delivery_address_id' => 'required|exists:addresses,id',
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            return $this->service->create($request->user(), $request);
        } catch (\Throwable $th) {
            // throw $th;
            $message = $th->getMessage() ?? 'An error occurred while placing order.';

            return $this->error($message, 500, null, $th);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            // dd($request->user()->orders());
            $order = $request->user()->orders()->with(['items.book', 'transaction', 'deliveryAddress'])->find($id);
            if (! $order) {
                return $this->error('Not Found', 404);
            }

            if (in_array($order->status, ['pending', 'processing'])) {
                $paymentIntent = $this->stripe->retrievePaymentIntent($order->transaction->payment_intent_id);
                $order->client_secret = $paymentIntent->client_secret ?? null;
            }

            return $this->success($order, 'Order detail');
        } catch (\Throwable $th) {
            // throw $th;
            $message = $th->getMessage() ?? 'An error occured while retrieving orders';

            return $this->error($message, 500, null, $th);
        }
    }

    public function track(Request $request, $tracking_number)
    {
        try {
            $order = $this->service->trackOrder($request->user(), $tracking_number);

            if (! $order /* || $order->isEmpty() */) {
                return $this->error('Resource not found.', 404);
            }

            return $this->success($order, 'Tracking information retrieved successfully.');
        } catch (\Throwable $th) {
            $message = $th->getMessage() ?: 'An error occurred while tracking your order. Please try again.';

            return $this->error($message, 500, null, $th);
        }
    }

    /**
     * Update the status of an order.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:completed,declined,cancelled,processing',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 400, $validator->errors());
        }

        try {
            $order = $request->user()->orders()->where('tracking_id', $id)->first();

            if (! $order) {
                return $this->error('Order not found', 404);
            }

            // Only allow the user who created the order to update its status
            if ($order->user_id !== $request->user()->id) {
                return $this->error('You do not have permission to update this order status.', 403);
            }

            // prevent status updates for orders that are already completed or cancelled and also prevent status reupdates for orders that are already in the requested status
            if (in_array($order->status, ['completed', 'cancelled']) || $order->status === $request->status) {
                return $this->error('Order status cannot be updated as it is already in the requested status or has been completed or cancelled.', 400);
            }

            // Only allow status to be set to 'completed' if current status is 'processing'
            if ($request->status === 'completed' && $order->status !== 'processing') {
                return $this->error('Order can only be marked as completed if it is in processing status.', 400);
            }

            if ($request->status === 'completed') {
                // set the analytics for the book
                $order->items->each(function ($item) {
                    // $item->book->increment('purchases');
                    // $item->book->increment('reads');
                    // Update the purchase count for the book in the analytics
                    $bookAnalytics = \App\Models\BookMetaDataAnalytics::firstOrCreate(
                        ['book_id' => $item->book->id],
                        ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                    );
                    $bookAnalytics->increment('purchases', 1);
                    $bookAnalytics->save();
                    $bookAnalytics->refresh(); // Refresh the model to get the updated values
                });

                // Update the transaction of type 'purchase' and purpose_id '$order->id', purpose_type 'order', status to 'succeeded'
                $transaction = \App\Models\Transaction::where('purpose_id', $order->id)
                    ->where('purpose_type', 'order')
                    ->where('type', 'purchase')
                    ->first();
                if ($transaction) {
                    $transaction->update(['status' => 'succeeded']);
                    // Optionally, you can also set the available_at date to now or added days for payout to be available
                    // $transaction->available_at = now();
                    $transaction->save();
                }
                // Update all the transactions of type 'earning' and purpose_id '$order->id', purpose_type 'order', status to 'available'
                $earnings = \App\Models\Transaction::where('purpose_id', $order->id)
                    ->where('purpose_type', 'order')
                    ->where('type', 'earning')
                    ->get();
                foreach ($earnings as $earning) {
                    $earning->update(['status' => 'available']);
                    // Optionally, you can also set the available_at date to now or added days for payout to be available
                    // $earning->available_at = now();
                    $earning->save();
                }
            }

            $order->status = $request->status;
            $order->save();

            return $this->success($order, 'Order status updated successfully');
        } catch (\Throwable $th) {
            return $this->error('An error occurred while updating order status.', 500, null, $th);
        }
    }
}
