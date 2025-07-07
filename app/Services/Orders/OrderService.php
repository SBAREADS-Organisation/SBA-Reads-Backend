<?php

namespace App\Services\Orders;

use App\Models\Book;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

class OrderService
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function create($user, $payload)
    {
        DB::beginTransaction();

        try {
            $order = Order::create([
                'user_id' => $user->id,
                'delivery_address_id' => $payload->delivery_address_id,
                'total_amount' => 0,
                'status' => 'pending',
                'tracking_number' => Order::generateTrackingNumber()
            ]);

            $total = 0;

            foreach ($payload->books as $item) {
                $book = Book::findOrFail($item['book_id']);
                // dd($book->pricing['actual_price']);
                $price = $book->pricing['actual_price'];
                $quantity = $item['quantity'];

                OrderItem::create([
                    'order_id' => $order->id,
                    'book_id' => $book->id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total_price' => $price * $quantity,
                ]);

                $total += $price * $quantity;
            }

            $order->update(['total_amount' => $total]);

            // dd($order);

            $transaction = $this->paymentService->createPayment([
                'amount' => $total,
                // pick currency from subscription model currencies []
                'currency' => $book->currency ?? $book->currency[0] ?? 'usd',
                'description' => "Books purchase", // Optimize by including the books names seperated by 'comas'.
                'purpose' => 'order',
                'purpose_id' => $order->id,
                // 'purpose_id' => $userSubscription->id,
                'meta_data' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                ],

            ], $user);

            $transaction = json_decode(json_encode($transaction));

            if (isset($transaction->error)) {
                // Rollback the subscription creation if payment fails
                DB::rollBack();
                return response()->json([
                    'data' => null,
                    'code' => 400,
                    'message' => $transaction->error,
                    'error' => $transaction->error,
                ], 400);
            }

            $transaction_id = $transaction->payment->id;

            // dd($transaction->payment->id);

            $order->update(['transaction_id' => $transaction_id]);

            // $order->transaction_id = $transaction->payment->id;

            DB::commit();

            // dd($transaction->payment);

            return ['order' => $order, 'transaction' => $transaction];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("An error occurred while creating the order: " . $e->getMessage(), 0, $e);
        }
    }

    public function trackOrder($user, $tracking_number)
    {
        try {
            return Order::where('tracking_number', $tracking_number)->with('items.book')->firstOrFail();
        } catch (\Exception $e) {
            throw new \Exception("An error occurred while tracking the order: " . $e->getMessage(), 0, $e);
            // throw $e;
        }
    }
}
