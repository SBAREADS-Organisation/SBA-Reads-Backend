<?php

namespace App\Services\Stripe;

use Stripe\Stripe;
use Stripe\Account;
use Stripe\Customer;
use Stripe\File;
use Stripe\Exception;
use Stripe\AccountUpdateParams;
use Stripe\PaymentMethod;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentMethod as PaymentMethodModel;
use App\Models\User;
use Illuminate\Http\Request;
use Stripe\Webhook;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StripeConnectService
{
    use ApiResponse;
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    function arrayToObject($array)
    {
        return json_decode(json_encode($array));
    }

    // Implement logic to create customer account and attach it to user kyc_customer_id
    public function createCustomer($user)
    {
        try {
            $customer = Customer::create([
                'email' => $user->email,
                // 'name' => $user->name,
                // 'name' => $user->first_name . ' ' . $user->last_name,
                // 'phone' => $user->phone,
                // 'address' => [
                //     'line1' => $user->address->line1,
                //     'city' => $user->address->city,
                //     'postal_code' => $user->address->postal_code,
                //     'state' => $user->address->state,
                //     'country' => strtoupper($user->address->country),
                // ],
                'description' => 'Customer for ' . $user->name || '',
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ]);

            if ($customer instanceof Exception\InvalidRequestException) {
                $error = $customer->getMessage();
                return response()->json([
                    'message' => 'Error creating Stripe customer',
                    'code' => 400,
                    'data' => null,
                    'error' => $error
                ], 400);
            }

            $user->kyc_customer_id = $customer['id'];
            $user->save();

            return $customer;
        } catch (\Throwable $th) {
            // dd($th);
            // Log::error('Stripe Customer Creation Error: ' . $th->getMessage());
            return $this->error('Error creating Stripe customer', 500, $th->getMessage(), $th);
        }
    }

    public function createCustomAccount($payload, $user)
    {
        try {
            $payload = json_decode(json_encode($payload));
            $isNigeria = strtoupper($payload->country) === 'NG';
            $email = $user->email;
            // Wrap Stripe account creation in a database transaction
            return DB::transaction(function () use ($payload, $user, $email, $isNigeria) {

                $account = Account::create([
                    'type' => 'custom',
                    'country' => strtoupper($payload->country ?? 'US'),
                    'email' => $email,
                    'settings' => [
                        'payouts' => [
                            'schedule' => [
                                'interval' => 'manual',
                            ],
                        ],
                    ],
                    'business_type' => 'individual',
                    'individual' => [
                        'first_name' => $payload->first_name,
                        'last_name' => $payload->last_name,
                        'dob' => [
                            'day' => $payload->dob->day,
                            'month' => $payload->dob->month,
                            'year' => $payload->dob->year,
                        ],
                        'email' => $email,
                        'address' => [
                            'line1' => $payload->address->line1,
                            'city' => $payload->address->city,
                            'postal_code' => $payload->address->postal_code,
                            'state' => $payload->address->state,
                            'country' => strtoupper($payload->country),
                        ],
                        'gender' => $payload->gender,
                        'phone' => $payload->phone,
                        'relationship' => [
                            'owner' => true,
                            'title' => 'CEO'
                        ],
                    ],
                    'tos_acceptance' => [
                        'date' => time(),
                        'ip' => request()->ip(),
                        'service_agreement' => 'recipient',
                        'user_agent' => request()->header('User-Agent'),
                    ],
                    'capabilities' => $isNigeria
                        ? [ // 🇳🇬 For Nigeria, only transfers
                            'transfers' => ['requested' => true],
                        ]
                        : [ // 🌎 For other countries, allow transfers + card payments
                            'transfers' => ['requested' => true],
                            'card_payments' => ['requested' => true],
                        ],
                ]);

                // check is the response is type of Exception froom stripe
                if ($account instanceof Exception\InvalidRequestException) {
                    $error = $account->getMessage();
                    return response()->json([
                        'message' => 'Error creating Stripe account',
                        'code' => 400,
                        'data' => null,
                        'error' => $error
                    ], 400);
                }

                $user->update(['kyc_account_id' => $account->id, 'kyc_status' => 'document-required', 'kyc_provider' => 'stripe', 'status' => 'pending']);
                $user->kycInfo()->create([
                    'first_name' => $payload->first_name,
                    'last_name' => $payload->last_name,
                    'dob' => Carbon::create($payload->dob->year, $payload->dob->month, $payload->dob->day),
                    'address_line1' => $payload->address->line1,
                    'address_line2' => $payload->address->line2 ?? null,
                    'city' => $payload->address->city,
                    'state' => $payload->address->state,
                    'postal_code' => $payload->address->postal_code,
                    'country' => $payload->country,
                    'phone' => $payload->phone,
                    'gender' => $payload->gender,
                ]);

                return $account;
            });
        } catch (\Throwable $th) {
            // Log::error('Stripe Account Creation Error: ' . $th->getMessage());
            return $this->error('Error creating Stripe account', 500, $th->getMessage(), $th);
        }
    }

    // implement the updateCustomAccount method
    public function updateCustomAccount($payload, $user)
    {
        try {
            $payload = json_decode(json_encode($payload));
            $isNigeria = strtoupper($payload->country) === 'NG';
            $email = $user->email;
            return DB::transaction(function () use ($payload, $user, $email, $isNigeria) {
                $account = Account::update(
                    $user->kyc_account_id,
                    [
                        'individual' => [
                            'first_name' => $payload->first_name,
                            'last_name' => $payload->last_name,
                            'dob' => [
                                'day' => $payload->dob->day,
                                'month' => $payload->dob->month,
                                'year' => $payload->dob->year,
                            ],
                            'email' => $email,
                            'address' => [
                                'line1' => $payload->address->line1,
                                'city' => $payload->address->city,
                                'postal_code' => $payload->address->postal_code,
                                'state' => $payload->address->state,
                                'country' => strtoupper($payload->country),
                            ],
                            'gender' => $payload->gender,
                            'phone' => $payload->phone,
                        ],
                    ]
                );

                // check is the response is type of Exception froom stripe
                if ($account instanceof Exception\InvalidRequestException) {
                    $error = $account->getMessage();
                    return response()->json([
                        'message' => 'Error updating Stripe account',
                        'code' => 400,
                        'data' => null,
                        'error' => $error
                    ], 400);
                    // return response()->json(['error' => $error], 400);
                }


                $user->update(['kyc_account_id' => $account->id, 'kyc_status' => 'document-required', 'kyc_provider' => 'stripe', 'status' => 'pending']);
                $user->kycInfo()->updateOrCreate(
                    [],
                    [
                    'first_name' => $payload->first_name,
                    'last_name' => $payload->last_name,
                    'dob' => Carbon::create($payload->dob->year, $payload->dob->month, $payload->dob->day),
                    'address_line1' => $payload->address->line1,
                    'address_line2' => $payload->address->line2 ?? null,
                    'city' => $payload->address->city,
                    'state' => $payload->address->state,
                    'postal_code' => $payload->address->postal_code,
                    'country' => $payload->country,
                    'phone' => $payload->phone,
                    'gender' => $payload->gender,
                ]);

                return $account;
            });
        } catch (\Throwable $th) {
            // Log::error('Stripe Account Update Error: ' . $th->getMessage());
            return $this->error('Error updating Stripe account', 500, $th->getMessage(), $th);
        }
    }

    public function uploadIdentityDocument($user, $filePath)
    {
        try {
            // dd($filePath);
            $file = File::create([
                'file' => fopen($filePath, 'r'),
                'purpose' => 'identity_document',
            ]);

            if ($file instanceof Exception\InvalidRequestException) {
                $error = $file->getMessage();
                return response()->json([
                    'message' => 'Error creating Stripe file',
                    'code' => 400,
                    'data' => null,
                    'error' => $error
                ], 400);
                // return response()->json(['error' => $error], 400);
            }

            $account = Account::update(
                $user->kyc_account_id,
                [
                    'individual' => [
                        'verification' => [
                            'document' => [
                                'front' => $file->id,
                            ]
                        ]
                    ]
                ]
            );

            // check is the response is type of Exception froom stripe
            if ($account instanceof Exception\InvalidRequestException) {
                $error = $account->getMessage();
                return response()->json([
                    'message' => 'Error updating Stripe account',
                    'code' => 400,
                    'data' => null,
                    'error' => $error
                ], 400);
                // return response()->json(['error' => $error], 400);
            }

            // delete the file after upload
            Storage::delete($filePath);

            // Update kyc status to in-review
            $user->kyc_status = 'in-review';
            $user->save();

            return $account;
        } catch (\Throwable $th) {
            //throw $th;
            // dd($th->getMessage());
            // Log::error('Stripe Document Upload Error: ' . $th->getMessage());
            return $this->error('Error uploading document to Stripe', 500, $th->getMessage() . ' ' . $user->kyc_account_id, $th);
        }
    }

    // Implement the webhook handler method
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Exception $e) {
            throw new \Exception("Failed to construct webhook event: " . $e->getMessage(), 0, $e);
        }

        if ($event->type === 'account.updated') {
            $account = $event->data->object;

            $user = User::where('kyc_account_id', $account->id)->first();

            if ($user) {
                if ($account->requirements->disabled_reason === null && $account->charges_enabled) {
                    $user->update(['kyc_status' => 'verified']);
                } elseif ($account->requirements->disabled_reason) {
                    $user->update(['kyc_status' => 'rejected']);
                }
            }
        }

        // Handle payment intent
        if ($event->type === 'payment_intent.succeeded') {
            // check the metadata for the purpose
            $paymentIntent = $event->data->object;
            $intent_purpose = $paymentIntent->metadata->purpose ?? null;
            $intent_purpose_id = $paymentIntent->metadata->purpose_id ?? null;
            $intent_reference = $paymentIntent->metadata->reference ?? null;

            if ($intent_purpose === 'order') {
                //TODO - consider using events later
                // Handle updating order status and creating an earnings record for each order item for its author
                // Find the order by the purpose_id populating the items and book for each item
                $order = \App\Models\Order::with(['items.book', 'transaction'])->where('id', $intent_purpose_id)->first();
                $transaction = \App\Models\Transaction::where('payment_intent_id', $paymentIntent->id)->first();
                if ($order && $transaction) {
                    // Update the order status to paid
                    $order->update(['status' => 'processing']);

                    // Create earnings for each item in the order
                    foreach ($order->items as $item) {
                        // Assuming each item has a book and the book has an author
                        if ($item->book && $item->book->authors->isNotEmpty()) {
                            $earning = new \App\Models\Transaction();
                            $earning->user_id = $item->book->authors->first()->id;
                            $earning->amount = $item->total_price; // Assuming price is the earning amount
                            $earning->currency = $paymentIntent->currency;
                            $earning->description = 'Earning from order #' . $order->id . ' for book ' . $item->book->title;
                            $earning->purpose_type = 'order';
                            $earning->purpose_id = $order->id;
                            $earning->type = 'earning';
                            $earning->purchased_by = $order->user_id;
                            $earning->direction = 'credit'; // Earning is a credit to the author's account
                            $earning->status = 'processing'; // At this point the order has not been completed yet
                            $earning->reference = 'ERN_' . $order->id . '_' . $item->book->id . '_' . time();
                            $earning->payment_provider = 'app';
                            $earning->payment_intent_id = $transaction->reference;
                            $earning->meta_data = json_encode([
                                'order_id' => $order->id,
                                'book_id' => $item->book->id,
                                'item_id' => $item->id,
                                'transaction_reference' => $intent_reference,
                            ]);
                            $earning->save();
                            $earning->refresh(); // Refresh the model to get the updated values

                            // Update the purchase count for the book in the analytics
                            // $bookAnalytics = \App\Models\BookMetaDataAnalytics::firstOrCreate(
                            //     ['book_id' => $item->book->id],
                            //     ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                            // );
                            // $bookAnalytics->increment('purchases', 1);
                            // $bookAnalytics->save();
                            // $bookAnalytics->refresh(); // Refresh the model to get the updated values

                            //NOTE - Add notification to the author about the earning
                        }
                    }
                }

                $transaction->update([
                    'status' => 'succeeded',
                ]);
                $transaction->refresh(); // Refresh the model to get the updated values
            }

            // Handle $intent_purpose === 'subscription'
            if ($intent_purpose === 'subscription') {
                // Find the user subscription by the purpose_id
                $userSubscription = \App\Models\UserSubscription::with([/*'user', */'subscription'])->where('id', $intent_purpose_id)->first();
                $txn = \App\Models\Transaction::where('payment_intent_id', $paymentIntent->id)->first();
                if ($userSubscription) {
                    // Update the subscription status to active
                    $userSubscription->update(['status' => 'active']);
                    $userSubscription->save();
                    $userSubscription->refresh(); // Refresh the model to get the updated values
                }
                //NOTE - Update the transaction with the subscription details
                $txn->update([
                    'status' => 'succeeded',
                    // 'type' => 'subscription',
                    // 'purpose_type' => 'subscription',
                    // 'purpose_id' => $userSubscription->id,
                ]);
                $txn->save();
                $txn->refresh(); // Refresh the model to get the updated values
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * ============================================================
     *                       PAYMENT METHODS
     * ============================================================
     */

    /**
     * Create a new payment method for the reader user.
     */
    public function addCard($payload, $user)
    {
        try {
            $existPaymentMethod = PaymentMethod::retrieve($payload->token);
            if ($existPaymentMethod instanceof Exception\InvalidRequestException) {
                $error = $existPaymentMethod->getMessage();
                return response()->json([
                    'message' => 'Error retrieving Stripe payment method',
                    'code' => 400,
                    'data' => null,
                    'error' => $error
                ], 400);
            }

            // Attach the payment method to the Stripe customer
            $existPaymentMethod->attach(['customer' => $user->kyc_customer_id]);

            // Set this payment method as the default payment method for the customer
            Customer::update($user->kyc_customer_id, [
                'invoice_settings' => ['default_payment_method' => $existPaymentMethod->id],
            ]);

            // Using stripe to create a new payment method
            // $paymentMethod = PaymentMethod::create([
            //     'type' => 'card',
            //     'card' => [
            //         'number' => $payload->card_number,
            //         'exp_month' => $payload->exp_month,
            //         'exp_year' => $payload->exp_year,
            //         'cvc' => $payload->cvc,
            //     ],
            //     'billing_details' => [
            //         'name' => $user->name,
            //         'email' => $user->email,
            //         // 'address' => [
            //         //     'line1' => $user->address->line1,
            //         //     'city' => $user->address->city,
            //         //     'postal_code' => $user->address->postal_code,
            //         //     'state' => $user->address->state,
            //         //     'country' => strtoupper($user->address->country),
            //         // ],
            //     ],
            // ]);

            // if ($paymentMethod instanceof Exception\InvalidRequestException) {
            //     $error = $paymentMethod->getMessage();
            //     return response()->json([
            //         'message' => 'Error creating Stripe payment method',
            //         'code' => 400,
            //         'data' => null,
            //         'error' => $error
            //     ], 400);
            // }

            // Store the payment method details in the database
            $paymentMethod = PaymentMethodModel::create([
                'user_id' => $user->id,
                'type' => 'card',
                // 'payment_platform' => 'stripe',
                'payment_data' => json_encode([
                    'card_last4' => $existPaymentMethod->card->last4,
                    'card_brand' => $existPaymentMethod->card->brand,
                    'exp_month' => $existPaymentMethod->card->exp_month,
                    'exp_year' => $existPaymentMethod->card->exp_year,
                ]),
                'purpose' => 'payment',
                'default' => true,
                'provider' => 'stripe',
                'provider_payment_method_id' => $existPaymentMethod->id,
                'metadata' => json_encode([
                    'customer_id' => $user->kyc_customer_id,
                    'payment_method_id' => $existPaymentMethod->id,
                ]),
                'country_code' => $user->country_code, // Assuming you have a country_code on the user model
            ]);

            // Check if the payment method was created successfully
            if (!$paymentMethod) {
                // PaymentMethod::delete($existPaymentMethod->id);
                return response()->json([
                    'message' => 'Error creating payment method in database',
                    'code' => 500,
                    'data' => null,
                    'error' => 'Database error'
                ], 500);
            }

            return $paymentMethod;
        } catch (\Throwable $th) {
            // dd($th);
            // Log::error('Stripe Payment Method Creation Error: ' . $th->getMessage());
            return $this->error('Error creating Stripe payment method', 500, $th->getMessage(), $th);
        }
    }

    /**
     * Create a new payment method for the author user add bank account.
     */
    public function addBankAccount($payload, $user)
    {
        try {
            $bankAccountData = [
                'account_number' => $payload['account_number'],
                'country' => $payload['country'],
            ];

            // For Nigeria (NG), we need to use the correct bank format
            if ($payload['country'] === 'NG') {
                $bankAccountData['sort_code'] = $payload['sort_code'];  // Nigeria uses sort code (Bank Code)
                // $bankAccountData['account_holder_name'] = $user->name;
                $bankAccountData['currency'] = 'NGN'; // USD or the local currency for Stripe payout
            }

            // For Canada (CA), we need to include the correct routing number
            if ($payload['country'] === 'CA') {
                $bankAccountData['routing_number'] = $payload['routing_number'];  // Canada uses routing number
                // $bankAccountData['account_holder_name'] = $user->name;
                $bankAccountData['currency'] = 'cad'; // Canadian dollars
            }

            $bankAccount = Account::createExternalAccount(
                $user->kyc_account_id,
                [
                    'external_account' => [
                        'object' => 'bank_account',
                        'country' => strtoupper($payload['country'] ?? 'US'),
                        'currency' => $payload['currency'],
                        'account_number' => $payload['account_number'],
                        'routing_number' => $payload['routing_number']
                    ],
                ]
            );

            // check is the response is type of Exception froom stripe
            if ($bankAccount instanceof Exception\InvalidRequestException) {
                $error = $bankAccount->getMessage();
                return response()->json([
                    'message' => 'Error creating Stripe bank account',
                    'code' => 400,
                    'data' => null,
                    'error' => $error
                ], 400);
            }

            $paymentMethod = PaymentMethodModel::create([
                'user_id' => $user->id,
                'type' => 'bank_account',
                'provider_payment_method_id' => $bankAccount->id,
                'provider' => 'stripe',
                'purpose' => 'payout',
                'default' => true,
                'payment_platform' => 'stripe',
                'payment_data' => json_encode([
                    'bank_account_last4' => $bankAccount->last4,
                    'bank_account_brand' => $bankAccount->brand,
                    'full_bank_account' => $payload->account_number,
                    // 'bank_account_account_number' => $bankAccount->account_number,
                    'bank_account_currency' => $bankAccount->currency,
                    'bank_account_country' => $bankAccount->country,
                    'bank_account_sort_code' => $validated['sort_code'] ?? null,
                    'bank_account_routing_number' => $validated['routing_number'] ?? null,
                ]),
                'metadata' => json_encode([
                    'customer_id' => $user->kyc_customer_id,
                    'payment_method_id' => $bankAccount->id,
                ]),
                'country_code' => $payload->country_code, // Store the country code for localization
            ]);

            // Check if the payment method was created successfully
            if (!$paymentMethod) {
                // PaymentMethod::delete($existPaymentMethod->id);
                return response()->json([
                    'message' => 'Error creating payment method in database',
                    'code' => 500,
                    'data' => null,
                    'error' => 'Database error'
                ], 500);
            }

            return $paymentMethod;
        } catch (\Throwable $th) {
            //            throw $th;
            // Log::error('Stripe Bank Account Creation Error: ' . $th->getMessage());
            return $this->error('Error creating Stripe bank account', 500, $th->getMessage(), $th);
        }
    }

    // List all payment methods for a user with search, filter and pagination
    public function listPaymentMethods($user, $filters = [])
    {
        try {
            $paymentMethods = PaymentMethodModel::where('user_id', $user->id)
                ->when(isset($filters['type']), function ($query) use ($filters) {
                    return $query->where('type', $filters['type']);
                })
                ->when(isset($filters['default']), function ($query) use ($filters) {
                    return $query->where('default', $filters['default']);
                })
                ->get();

            // Handle pagination if needed
            // $paymentMethods = $paymentMethods->paginate($filters['per_page'] ?? 10);

            // Error handling for empty results
            if ($paymentMethods->isEmpty()) {
                return response()->json([
                    'message' => 'No payment methods found',
                    'code' => 404,
                    'data' => null,
                    'error' => 'No payment methods found'
                ], 404);
            }

            return response()->json([
                'message' => 'Payment methods retrieved successfully',
                'code' => 200,
                'data' => $paymentMethods,
                'error' => null
            ], 200);
        } catch (\Throwable $th) {
            // dd($th);
            // Log::error('Stripe List Payment Methods Error: ' . $th->getMessage());
            return $this->error('Error retrieving payment methods', 500, $th->getMessage(), $th);
        }
    }

    // Payment intent creation
    public function createPaymentIntent($payload, $user)
    {
        try {
            $paymentIntent = \Stripe\PaymentIntent::create([
                // convert amount to cents based on currency provided
                'amount' => $payload['amount'] * 100,
                // 'amount' => $payload['amount'],
                'currency' => $payload['currency'],
                'customer' => $user->kyc_customer_id,
                // calculate the application fee added with the payment
                // 'application_fee_amount' => $payload['application_fee_amount'] * 100,
                // if the user had a payment method saved, use it
                // 'payment_method' => $user->default_payment_method_id,
                // 'payment_method_types' => ['card'],
                'confirm' => true, //REVIEW - this will confirm the payment immediately
                'metadata' => [
                    'user_id' => $user->id,
                    'description' => $payload['description'] ?? '',
                    'purpose' => $payload['purpose'] ?? '',
                    'reference' => $payload['reference'],
                    'reference_id' => $payload['purpose_id'],
                ],
                'description' => $payload['description'] ?? '',
                'automatic_payment_methods' => [
                    'enabled' => true,
                ]
            ]);

            // dd('PAYMENT INTENT', $paymentIntent);

            // Error Handling
            if ($paymentIntent instanceof Exception\InvalidRequestException) {
                $error = $paymentIntent->getMessage();
                return response()->json([
                    'message' => 'Error initiating Payment',
                    'code' => 400,
                    'data' => null,
                    'error' => $error
                ], 400);
                // return response()->json(['error' => $error], 400);
            }

            return $paymentIntent;
        } catch (\Throwable $th) {
            // dd($th);
            // dd($th);
            // Log::error('Stripe Payment Intent Creation Error: ' . $th->getMessage());
            return $this->error('Error creating payment intent', 500, $th->getMessage(), $th);
        }
    }

    // retrievePaymentIntent
    public function retrievePaymentIntent($paymentIntentId)
    {
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            if ($paymentIntent instanceof Exception\InvalidRequestException) {
                $error = $paymentIntent->getMessage();
                return response()->json([
                    'message' => 'Error retrieving Payment Intent',
                    'code' => 400,
                    'data' => null,
                    'error' => $error
                ], 400);
            }

            return $paymentIntent;
        } catch (\Throwable $th) {
            return $this->error('Error retrieving Payment Intent', 500, $th->getMessage(), $th);
        }
    }
}
