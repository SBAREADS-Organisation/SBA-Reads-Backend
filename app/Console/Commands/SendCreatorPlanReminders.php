<?php

namespace App\Console\Commands;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Reminds SBA Creators before a free trial or paid plan ends, and tells them when it has.
 * Runs once a day; each window is one day wide, so every store gets each email once.
 */
class SendCreatorPlanReminders extends Command
{
    protected $signature = 'creators:plan-reminders';

    protected $description = 'Email creators whose trial or plan ends soon or has just ended';

    public function handle(): int
    {
        $soonFrom = now()->addDays(3);
        $soonTo = now()->addDays(4);
        $endedFrom = now()->subDay();
        $studio = rtrim((string) config('creators.website_url'), '/') . '/author/creator';
        $sent = 0;

        $plans = config('creators.plans');
        $fee = fn (string $plan) => rtrim(rtrim(number_format($plans[$plan]['fee_ngn'] * 100, 1), '0'), '.') . '%';

        $send = function (CreatorStore $store, string $subject, string $body) use (&$sent) {
            try {
                Mail::to($store->user->email)->queue(new GenericAppNotification($subject, $body));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Creator plan reminder failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
            }
        };

        $paidPlan = fn ($q) => $q->where('plan', '!=', 'starter')->whereNotNull('plan_paid_until')->where('plan_paid_until', '>', now());

        // Trial ending soon, with no paid plan lined up after it.
        CreatorStore::with('user')->whereBetween('trial_ends_at', [$soonFrom, $soonTo])
            ->whereNot($paidPlan)
            ->each(function (CreatorStore $store) use ($send, $studio, $fee) {
                $send($store, 'Your free month of SBA Creators Pro ends in 3 days',
                    "Your free Pro month for {$store->name} ends on {$store->trial_ends_at->format('j F Y')}.\n\n"
                    . 'After that your store moves to Starter, with a ' . $fee('starter') . ' fee per sale instead of ' . $fee('pro') . ".\n\n"
                    . "Keep the lower fee by choosing Pro in Creator Studio:\n{$studio}");
            });

        // Paid plan ending soon.
        CreatorStore::with('user')->where('plan', '!=', 'starter')->whereBetween('plan_paid_until', [$soonFrom, $soonTo])
            ->each(function (CreatorStore $store) use ($send, $studio, $plans) {
                $name = $plans[$store->plan]['name'];
                $send($store, "Your SBA Creators {$name} plan ends in 3 days",
                    "Your {$name} plan for {$store->name} is paid until {$store->plan_paid_until->format('j F Y')}.\n\n"
                    . "Renew in Creator Studio to keep your lower fees:\n{$studio}");
            });

        // Paid plan or trial just ended: the store is now on Starter.
        CreatorStore::with('user')
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('plan', '!=', 'starter')->whereBetween('plan_paid_until', [$endedFrom, now()]))
                ->orWhere(fn ($t) => $t->whereBetween('trial_ends_at', [$endedFrom, now()])->whereNot($paidPlan)))
            ->each(function (CreatorStore $store) use ($send, $studio, $fee) {
                if ($store->activePlan() !== 'starter') {
                    return;
                }
                $send($store, 'Your SBA Creators store is now on Starter',
                    "Your store {$store->name} is still open and selling, now on the free Starter plan with a " . $fee('starter') . " fee per sale.\n\n"
                    . "Choose Pro or Business any time in Creator Studio for lower fees:\n{$studio}");
            });

        $this->info("Sent {$sent} creator plan email(s).");

        return self::SUCCESS;
    }
}
