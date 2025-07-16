<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Models\Transaction;
use App\Notifications\Subscriptions\SubscriptionActivatedNotification;
use App\Notifications\Subscriptions\SubscriptionExpiredNotification;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }
    /**
     * Subscribe a user to a subscription.
     *
     * @param \App\Models\User $user
     * @param int $subscriptionId
     * @return void
     */
    public function subscribe($user, $subscriptionId)
    {
        $subscription = Subscription::findOrFail($subscriptionId);

        $subscription = json_decode(json_encode($subscription));

        // dd($user->activeSubscription());
        // // Check if the user already has an active subscription
        // if ($user->activeSubscription()) {
        //     return response()->json([
        //         'data' => null,
        //         'code' => 400,
        //         'message' => 'You already have an active subscription.',
        //         'error' => 'You already have an active subscription.'
        //     ], 400);
        // }

        return DB::transaction(function () use ($user, $subscription) {
            $user->activeSubscription()?->update(['status' => 'expired']);

            $userSubscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays($subscription->duration_in_days),
                'status' => 'in-transaction',
            ]);

            $userSubscription = json_decode(json_encode($userSubscription));

            $transaction = $this->paymentService->createPayment([
                'amount' => $subscription->price,
                // pick currency from subscription model currencies []
                'currency' => $subscription->currencies[0] ?? 'usd',
                // 'currency' => 'usd',
                'description' => "Subscription to {$subscription->title}",
                'purpose' => 'subscription',
                // 'purpose_id' => $subscription->id,
                'purpose_id' => $userSubscription->id,
                'meta_data' => [
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                ],
            ], $user);

            $transaction = json_decode(json_encode($transaction));

            // dd($transaction);

            if (isset($transaction->error)) {
                // Rollback the subscription creation if payment fails
                $userSubscription->delete();
                return response()->json([
                    'data' => null,
                    'code' => 400,
                    'message' => $transaction->error,
                    'error' => $transaction->error,
                ], 400);
            }

            // Transaction::create([
            //     'user_id' => $user->id,
            //     'type' => 'subscription',
            //     'amount' => $subscription->price,
            //     'reference' => uniqid('sub_'),
            //     'status' => 'success',
            // ]);

            // $user->notify(new SubscriptionActivatedNotification($userSubscription));
            // return transaction details and user subscription details
            $response = [
                'client_secret' => $transaction->client_secret,
                'subscription' => $userSubscription,
                'transaction' => $transaction,
            ];
            // $user->notify(new SubscriptionActivatedNotification($userSubscription));
            // dd($response);

            return response()->json([
                'code' => 200,
                'data' => $response,
                'message' => 'Subscription successfully activated.',
                'error' => null,
            ], 200);
        });
    }

    /**
     * Renew a user's subscription.
     *
     * @param \App\Models\UserSubscription $userSubscription
     * @return void
     */
    // public function renew($userSubscription)
    // {
    //     $userSubscription->update([
    //         'ends_at' => now()->addDays($userSubscription->subscription->duration_in_days),
    //         'status' => 'active'
    //     ]);

    //     $userSubscription->user->notify(new SubscriptionRenewalNotification($userSubscription));
    // }

    /**
     * Check if a user's subscription is expired and update the status.
     *
     * @param \App\Models\UserSubscription $userSubscription
     * @return void
     */
    public function expireIfNeeded($userSubscription)
    {
        if ($userSubscription->isExpired()) {
            $userSubscription->update(['status' => 'expired']);
            $userSubscription->user->notify(new SubscriptionExpiredNotification($userSubscription));
        }
    }

    /**
     * Get the active subscription for a user.
     *
     * @param \App\Models\User $user
     * @return \App\Models\UserSubscription|null
     */
    public function getActiveSubscription($user)
    {
        return $user->activeSubscription;
    }

    /**
     * Get all subscriptions for a user.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllSubscriptions($user)
    {
        return $user->subscriptions()->with('subscription')->get();
    }

    /**
     * Get all available subscriptions.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllAvailableSubscriptions()
    {
        return Subscription::all();
    }

    /**
     * Get a specific subscription by ID.
     *
     * @param int $subscriptionId
     * @return \App\Models\Subscription|null
     */
    public function getSubscriptionById($subscriptionId)
    {
        return Subscription::find($subscriptionId);
    }

    /**
     * create a  subscription.
     *
     * @param object $payload
     * @return \App\Models\Subscription
     */
    public function createSubscription($payload)
    {
        return Subscription::create([
            'title' => $payload->title,
            'price' => $payload->price,
            'duration_in_days' => $payload->duration_in_days,
            'perks' => $payload->perks,
            'model' => $payload->model,
            'currencies' => $payload->currencies ?? json_encode([]),
        ]);
    }

    /**
     * Update a subscription.
     *
     * @param \App\Models\Subscription $subscription
     * @param object $payload
     * @return \App\Models\Subscription
     */
    public function updateSubscription($subscription, $payload)
    {
        $subscription->update([
            'title' => $payload->title,
            'price' => $payload->price,
            'duration_in_days' => $payload->duration_in_days,
            'perks' => $payload->perks,
        ]);

        return $subscription;
    }

    /**
     * Delete a subscription.
     *
     * @param \App\Models\Subscription $subscription
     * @return void
     */
    public function deleteSubscription($subscription)
    {
        $subscription->delete();
    }

    /**
     * Get active subscription count.
     * 
     * return int
     */
    public function getActiveSubscriptionCount(): int
    {
        $activeCount = UserSubscription::where('starts_at', '<', now())->where('ends_at', '>', now())->count();
        return $activeCount;
    } 
}
