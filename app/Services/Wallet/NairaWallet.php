<?php

namespace App\Services\Wallet;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * An author's naira wallet, worked out from their transactions.
 *
 *  earned    every naira earning (book sales, SBA Creators sales, affiliate commissions)
 *  pending   earnings younger than the hold period (config payments.wallet_hold_days)
 *  paid_out  withdrawals (sent or on the way) and automatic payouts already sent
 *  available what can be withdrawn now: cleared earnings less everything paid out
 */
class NairaWallet
{
    /** Automatic payouts the payout job already sent to the author's bank (recorded as credits). */
    private static function autoPayout(Builder $q): Builder
    {
        return $q->where('direction', 'credit')
            ->whereIn('payment_provider', ['paystack', 'stripe'])
            ->whereIn('purpose_type', ['order', 'digital_book_purchase']);
    }

    /** Money owed to authors: succeeded earning credits, not the records of automatic payouts. */
    private static function earnings(Builder $q): Builder
    {
        return $q->where('direction', 'credit')->where('status', 'succeeded')
            ->whereIn('purpose_type', self::EARNING_PURPOSES)
            ->where(fn ($w) => $w->whereNull('payment_provider')->orWhereNotIn('payment_provider', ['paystack', 'stripe']));
    }

    private static function naira(int $userId): Builder
    {
        return Transaction::where('user_id', $userId)->whereRaw("UPPER(currency) = 'NGN'");
    }

    /** @return array{earned: float, pending: float, paid_out: float, available: float, next_release_at: ?Carbon, hold_days: int} */
    public static function for(int $userId): array
    {
        $holdDays = (int) config('payments.wallet_hold_days', 7);
        $clearedBefore = now()->subDays($holdDays);

        $earnings = fn () => self::earnings(self::naira($userId));

        $earned = (float) $earnings()->sum('amount');
        $cleared = (float) $earnings()->where('created_at', '<=', $clearedBefore)->sum('amount');
        $oldestPending = $earnings()->where('created_at', '>', $clearedBefore)->min('created_at');

        $paidOut = (float) self::naira($userId)->whereIn('status', ['succeeded', 'pending'])
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('direction', 'debit')->where('purpose_type', 'paystack_payout'))
                ->orWhere(fn ($a) => self::autoPayout($a)))
            ->sum('amount');

        return [
            'earned' => round($earned, 2),
            'pending' => round(max(0, $earned - $cleared), 2),
            'paid_out' => round($paidOut, 2),
            'available' => round(max(0, $cleared - $paidOut), 2),
            'next_release_at' => $oldestPending ? Carbon::parse($oldestPending)->addDays($holdDays) : null,
            'hold_days' => $holdDays,
        ];
    }

    /** Everything owed to all authors in naira (pending and available), for the daily balance check. */
    public static function totalOwed(): float
    {
        $earned = (float) self::earnings(Transaction::whereRaw("UPPER(currency) = 'NGN'"))->sum('amount');
        $paidOut = (float) Transaction::whereRaw("UPPER(currency) = 'NGN'")->whereIn('status', ['succeeded', 'pending'])
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('direction', 'debit')->where('purpose_type', 'paystack_payout'))
                ->orWhere(fn ($a) => self::autoPayout($a)))
            ->sum('amount');

        return round(max(0, $earned - $paidOut), 2);
    }

    /** The kinds of credit that are money owed to an author (not a buyer's own payment). */
    public const EARNING_PURPOSES = ['digital_book_purchase', 'audio_book_purchase', 'order', 'creator_sale', 'creator_affiliate'];
}
