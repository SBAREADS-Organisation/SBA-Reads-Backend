<?php

namespace App\Console\Commands;

use App\Services\Creators\CreatorAlerts;
use App\Services\Paystack\PaystackTransferService;
use App\Services\Wallet\NairaWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Every morning: is there enough in the SBA Reads Paystack account to pay every author what they're owed?
 * If not, the team is alerted so money can be moved in (for example from Stripe) before payouts fail.
 */
class CheckWalletBalance extends Command
{
    protected $signature = 'wallet:check-balance';

    protected $description = 'Alert the team if the Paystack balance is below what authors are owed in naira';

    public function handle(PaystackTransferService $paystack): int
    {
        $owed = NairaWallet::totalOwed();
        $held = $paystack->nairaBalance();
        $line = 'Authors are owed ₦' . number_format($owed, 2) . '; the Paystack balance is ₦' . number_format($held, 2) . '.';
        Log::info('Wallet balance check: ' . $line);
        $this->info($line);

        if ($held < $owed) {
            CreatorAlerts::send('The Paystack balance is below what authors are owed', [
                'owed_to_authors' => '₦' . number_format($owed, 2),
                'paystack_balance' => '₦' . number_format($held, 2),
                'short_by' => '₦' . number_format($owed - $held, 2),
                'what_to_do' => 'Top up the Paystack balance (for example from Stripe) before authors withdraw.',
            ]);
            $this->warn('Short by ₦' . number_format($owed - $held, 2) . ' — the team has been alerted.');
        }

        return self::SUCCESS;
    }
}
