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
            // Create or find address from string
            $addressId = $this->handleDeliveryAddress($user, $payload->delivery_address);

            $order = Order::create([
                'user_id' => $user->id,
                'delivery_address_id' => $addressId,
                'total_amount' => 0,
                'status' => 'pending',
                'tracking_number' => Order::generateTrackingNumber(),
            ]);

            $total = 0;

            foreach ($payload->books as $item) {
                $book = Book::findOrFail($item['book_id']);
                $price = $book->pricing['actual_price'];
                $quantity = $item['quantity'];
                $totalPrice = $price * $quantity;
                $authorPayout = $totalPrice * 0.8;

                OrderItem::create([
                    'order_id' => $order->id,
                    'book_id' => $book->id,
                    'author_id' => $book->author_id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total_price' => $totalPrice,
                    'author_payout_amount' => $authorPayout,
                    'platform_fee_amount' => $totalPrice - $authorPayout,
                ]);

                $total += $totalPrice;
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
                'Order created successfully. Please proceed to payment.'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('An error occurred while creating the order: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle delivery address - create new address from string
     */
    private function handleDeliveryAddress($user, $addressString)
    {
        // Create a new address record from the string
        $address = \App\Models\Address::create([
            'user_id' => $user->id,
            'address' => $addressString,
            'city' => 'Not specified', // Default values
            'country' => 'Not specified',
            'postal_code' => '00000',
            'is_default' => false,
        ]);

        return $address->id;
    }

    public function trackOrder($user, $tracking_number)
    {
        try {
            return Order::where('tracking_number', $tracking_number)->with('items.book')->firstOrFail();
        } catch (\Exception $e) {
            throw new \Exception('An error occurred while tracking the order: ' . $e->getMessage(), 0, $e);
            // throw $e;
        }
    }
}
