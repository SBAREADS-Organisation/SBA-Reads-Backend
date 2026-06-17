<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\UserSubscription;
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
     * @param  \App\Models\User  $user
     * @param  int  $subscriptionId
     * @return void
     */
    public function subscribe($user, $subscriptionId)
    {
        $subscription = Subscription::findOrFail($subscriptionId);

        return DB::transaction(function () use ($user, $subscription) {
            // Expire any existing active subscription
            $user->activeSubscription()?->update(['status' => 'expired']);

            // Activate immediately — payment integration will be wired in a later release
            $userSubscription = UserSubscription::create([
                'user_id'         => $user->id,
                'subscription_id' => $subscription->id,
                'starts_at'       => now(),
                'ends_at'         => now()->addDays($subscription->duration_in_days),
                'status'          => 'active',
            ]);

            $userSubscription->load('subscription');

            return $userSubscription;
        });
    }

    /**
     * Get the current active subscription for a user.
     */
    public function getCurrentSubscription($user): ?UserSubscription
    {
        return UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->with('subscription')
            ->latest()
            ->first();
    }

    /**
     * Renew a user's subscription.
     *
     * @param  \App\Models\UserSubscription  $userSubscription
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
     * @param  \App\Models\UserSubscription  $userSubscription
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
     * @param  \App\Models\User  $user
     * @return \App\Models\UserSubscription|null
     */
    public function getActiveSubscription($user)
    {
        return $user->activeSubscription;
    }

    /**
     * Get all subscriptions for a user.
     *
     * @param  \App\Models\User  $user
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
     * @param  int  $subscriptionId
     * @return \App\Models\Subscription|null
     */
    public function getSubscriptionById($subscriptionId)
    {
        return Subscription::find($subscriptionId);
    }

    /**
     * create a  subscription.
     *
     * @param  object  $payload
     * @return \App\Models\Subscription
     */
    public function createSubscription($payload)
    {
        return Subscription::create([
            'title' => $payload->title,
            'price' => $payload->price,
            'duration_in_days' => $payload->duration_in_days,
            'perks' => $payload->perks ?? json_encode([]),
            'model' => $payload->model,
            'currencies' => $payload->currencies ?? json_encode([]),
        ]);
    }

    /**
     * Update a subscription.
     *
     * @param  \App\Models\Subscription  $subscription
     * @param  object  $payload
     * @return \App\Models\Subscription
     */
    public function updateSubscription($subscription, $payload)
    {
        $subscription->update([
            'title' => $payload->title,
            'price' => $payload->price,
            'duration_in_days' => $payload->duration_in_days,
            'perks' => $payload->perks ?? json_encode([]),
            'model' => $payload->model,
            'currencies' => $payload->currencies ?? json_encode([]),
        ]);

        return $subscription;
    }

    /**
     * Delete a subscription.
     *
     * @param  \App\Models\Subscription  $subscription
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
