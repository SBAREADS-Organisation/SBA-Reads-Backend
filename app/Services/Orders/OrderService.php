<?php

namespace App\Services\Orders;

use App\Models\Book;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderService
{
    use ApiResponse;
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
                'tracking_number' => Order::generateTrackingNumber(),
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

            $transaction = $this->paymentService->createPayment([
                'amount' => $total,
                'currency' => $book->currency ?? $book->currency[0] ?? 'usd',
                'description' => 'Book order',
                'purpose' => 'order',
                'purpose_id' => $order->id,
                'meta_data' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                ],
            ], $user);

            if ($transaction instanceof JsonResponse) {
                $responseData = $transaction->getData(true);

                return $this->error(
                    'An error occurred while initiating the books order process.',
                    $transaction->getStatusCode(),
                    $responseData['error'] ?? 'Unknown error from payment service.'
                );
            }

            $order->update(['transaction_id' => $transaction->id]);

            DB::commit();

            return $this->success(
                ['order' => $order, 'transaction' => $transaction],
                'Order created successfully. Please proceed to payment.');
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('An error occurred while creating the order: '.$e->getMessage(), 0, $e);
        }
    }

    public function trackOrder($user, $tracking_number)
    {
        try {
            return Order::where('tracking_number', $tracking_number)->with('items.book')->firstOrFail();
        } catch (\Exception $e) {
            throw new \Exception('An error occurred while tracking the order: '.$e->getMessage(), 0, $e);
            // throw $e;
        }
    }
}
