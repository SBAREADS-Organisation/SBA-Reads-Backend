<?php

namespace App\Console\Commands\Subscriptions;

use App\Models\UserSubscription;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire ended subscriptions';

    public function handle()
    {
        $subscriptions = UserSubscription::where('status', 'active')
            ->where('ends_at', '<', now())
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->update(['status' => 'expired']);
            $subscription->user->notify(new \App\Notifications\Subscriptions\SubscriptionExpiredNotification($subscription));
        }

        $this->info('Expired subscriptions processed.');
    }
}
