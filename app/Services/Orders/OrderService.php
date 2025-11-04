<?php

namespace App\Services\Orders;

use App\Models\Book;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Paystack\CurrencyConversionService;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderService
{
    use ApiResponse;

    protected $paymentService;
    protected $currencyConversionService;

    public function __construct(PaymentService $paymentService, CurrencyConversionService $currencyConversionService)
    {
        $this->paymentService = $paymentService;
        $this->currencyConversionService = $currencyConversionService;
    }

    public function create($user, $payload)
    {
        DB::beginTransaction();

        try {
            // Create or find address from string
            $addressId = $this->handleDeliveryAddress($user, $payload->delivery_address);

            // Get currency from payload, default to USD if not provided
            $currency = isset($payload->currency) ? strtoupper($payload->currency) : 'USD';

            $order = Order::create([
                'user_id' => $user->id,
                'delivery_address_id' => $addressId,
                'total_amount' => 0,
                'status' => 'pending',
                'tracking_number' => Order::generateTrackingNumber(),
                'currency' => $currency,
            ]);

            $total = 0;

            foreach ($payload->books as $item) {
                $book = Book::findOrFail($item['book_id']);
                $price = $book->pricing['actual_price'];
                $quantity = $item['quantity'];
                $totalPrice = $price * $quantity;
                $authorPayout = $totalPrice * 0.7;

                OrderItem::create([
                    'order_id' => $order->id,
                    'book_id' => $book->id,
                    'author_id' => $book->author_id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total_price' => $this->currencyConversionService->convert((float) $totalPrice, 'USD', $currency),
                    'total_price_usd' => $totalPrice,
                    'author_payout_amount' => $this->currencyConversionService->convert($authorPayout, 'USD', $currency),
                    'author_payout_amount_usd' => $authorPayout,
                    'platform_fee_amount' => $this->currencyConversionService->convert($totalPrice - $authorPayout, 'USD', $currency),
                    'platform_fee_amount_usd' => $totalPrice - $authorPayout,
                ]);

                $total += $totalPrice;
            }



            // Convert total amount from USD to requested currency if needed
            $payableAmount = $total;
            if ($currency !== 'USD') {
                try {
                    $payableAmount = $this->currencyConversionService->convert((float) $total, 'USD', $currency);
                } catch (\Exception $e) {
                    // If conversion fails, rethrow to be handled by outer catch/rollback
                    throw new \Exception('Unable to convert currency at this time: ' . $e->getMessage(), 0, $e);
                }
            }

            $order->update(['total_amount' => $payableAmount]);

            // Determine the appropriate payment provider based on currency
            $provider = $this->getPaymentProvider($currency);

            $transaction = $this->paymentService->createPayment([
                'amount' => $payableAmount,
                'currency' => $currency,
                'description' => 'Book order',
                'purpose' => 'order',
                'purpose_id' => $order->id,
                'payment_provider' => $provider,
                'meta_data' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'currency' => $currency,
                    'amount_usd' => $total,
                    'amount_converted' => $payableAmount,
                ],
            ], $user);

            if ($transaction instanceof JsonResponse) {
                $responseData = $transaction->getData(true);
                return $this->error(
                    'An error occurred while initiating the books order process.' . ($responseData['error'] ?? 'Unknown error from payment service.'),
                    $transaction->getStatusCode()
                );
            }

            $order->update(['transaction_id' => $transaction->id]);

            DB::commit();

            return $this->success(
                [
                    'order' => $order,
                    'transaction' => $transaction,
                    'provider' => $provider,
                    'currency' => $currency,
                    // Payment processing fields
                    'authorization_url' => $provider === 'paystack' ? $transaction->payment_client_secret : null
                ],
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

    /**
     * Determine the appropriate payment provider based on currency
     *
     * @param string $currency
     * @return string
     */
    private function getPaymentProvider(string $currency): string
    {
        $currency = strtoupper($currency);

        // Provider currency mapping
        $stripeSupported = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
        $paystackSupported = ['NGN', 'GHS', 'KES', 'ZAR'];

        // African currencies prioritize Paystack
        $africanCurrencies = ['NGN', 'GHS', 'KES', 'ZAR'];
        if (in_array($currency, $africanCurrencies)) {
            return 'paystack';
        }

        // For USD, prefer Stripe for global use
        if ($currency === 'USD') {
            return 'stripe';
        }

        // Check Stripe support first
        if (in_array($currency, $stripeSupported)) {
            return 'stripe';
        }

        // Check Paystack support
        if (in_array($currency, $paystackSupported)) {
            return 'paystack';
        }

        // Default to Stripe for unsupported currencies
        return 'stripe';
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
