<?php

namespace App\Console\Commands;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorPurchase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Memberships don't renew by themselves, so members are told a few days before one ends and again when it has.
 * Only each member's latest payment counts, and each email is sent once (tracked on the purchase).
 */
class SendCreatorMembershipReminders extends Command
{
    protected $signature = 'creators:membership-reminders';

    protected $description = 'Email members whose SBA Creators membership ends soon or has just ended';

    public function handle(): int
    {
        $sent = 0;
        $latest = fn ($query) => $query->whereNotExists(fn ($later) => $later->from('creator_purchases as later')
            ->whereColumn('later.product_id', 'creator_purchases.product_id')
            ->whereColumn('later.buyer_id', 'creator_purchases.buyer_id')
            ->where('later.status', 'paid')
            ->whereColumn('later.access_until', '>', 'creator_purchases.access_until'));
        $base = fn () => CreatorPurchase::with(['product', 'store', 'buyer'])
            ->where('status', 'paid')->whereNotNull('access_until')
            ->whereHas('product', fn ($q) => $q->where('type', 'membership'))
            ->where($latest);

        foreach ($base()->whereNull('renew_reminded_at')->whereBetween('access_until', [now(), now()->addDays(3)])->get() as $purchase) {
            // Members who chose automatic renewal are told when their card will be charged instead.
            if ($purchase->auto_renew && $purchase->card) {
                $sent += $this->send($purchase, 'renew_reminded_at',
                    "Your membership of \"{$purchase->product->title}\" renews soon",
                    "Your membership of \"{$purchase->product->title}\" by {$purchase->store->name} renews automatically on "
                    . $purchase->access_until->copy()->subDay()->format('l j F') . ', using your ' . $purchase->card->label() . ".\n\n"
                    . "Nothing to do if you'd like to stay. To stop it renewing, turn off automatic renewal here:\n" . $this->link($purchase));
                continue;
            }
            $sent += $this->send($purchase, 'renew_reminded_at',
                "Your membership of \"{$purchase->product->title}\" ends soon",
                "Your membership of \"{$purchase->product->title}\" by {$purchase->store->name} ends on "
                . $purchase->access_until->format('l j F') . ".\n\n"
                . "Memberships don't renew by themselves. To keep your access, renew here:\n" . $this->link($purchase)
                . "\n\nRenewing early is fine: the new month starts when your current one ends.");
        }

        foreach ($base()->whereNull('ended_notified_at')->whereBetween('access_until', [now()->subDays(2), now()])->get() as $purchase) {
            $sent += $this->send($purchase, 'ended_notified_at',
                "Your membership of \"{$purchase->product->title}\" has ended",
                "Your membership of \"{$purchase->product->title}\" by {$purchase->store->name} has ended.\n\n"
                . "You can rejoin any time and pick up where you left off:\n" . $this->link($purchase));
        }

        $this->info("Sent {$sent} membership emails.");

        return self::SUCCESS;
    }

    private function link(CreatorPurchase $purchase): string
    {
        return rtrim((string) config('creators.website_url'), '/') . '/learn/' . $purchase->product_id;
    }

    private function send(CreatorPurchase $purchase, string $flag, string $subject, string $body): int
    {
        try {
            Mail::to($purchase->buyer->email)->queue(new GenericAppNotification($subject, $body));
            $purchase->update([$flag => now()]);

            return 1;
        } catch (\Throwable $e) {
            Log::warning("Membership reminder failed for purchase {$purchase->id}: " . $e->getMessage());

            return 0;
        }
    }
}
