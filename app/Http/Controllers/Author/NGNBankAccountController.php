<?php

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Services\Paystack\PaystackTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NGNBankAccountController extends Controller
{
    public function __construct(protected PaystackTransferService $paystack) {}

    /**
     * Resolve the account holder's name for a given account number + bank code.
     * Called by the app as the author types their 10-digit account number so the
     * name is auto-filled — they can confirm it before saving.
     * POST /author/wallet/resolve-account
     */
    public function resolveAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_number' => 'required|string|size:10|regex:/^\d{10}$/',
            'bank_code'      => 'required|string|max:10',
        ]);

        try {
            $accountName = $this->paystack->resolveAccount(
                $validated['account_number'],
                $validated['bank_code'],
            );

            if (empty($accountName)) {
                return $this->error('Account not found. Please check the account number and bank.', 422);
            }

            return $this->success([
                'account_name'   => $accountName,
                'account_number' => $validated['account_number'],
                'bank_code'      => $validated['bank_code'],
            ], 'Account resolved successfully.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Register a Nigerian bank account for NGN payouts.
     * Creates a Paystack Transfer Recipient and stores the code on the author.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_name'   => 'required|string|max:100',
            'account_number' => 'required|string|size:10',
            'bank_code'      => 'required|string|max:10',
        ]);

        $user = $request->user();

        try {
            // Resolve bank name from code for display purposes
            $banks    = $this->paystack->listBanks();
            $bankName = collect($banks)->firstWhere('code', $validated['bank_code'])['name'] ?? $validated['bank_code'];

            $recipientCode = $this->paystack->createRecipient(
                $validated['account_name'],
                $validated['account_number'],
                $validated['bank_code'],
            );

            $user->update([
                'paystack_recipient_code' => $recipientCode,
                'payout_method'           => 'paystack',
                'bank_name'               => $bankName,
                'bank_account_name'       => $validated['account_name'],
                'bank_account_number'     => $validated['account_number'],
                'bank_code'               => $validated['bank_code'],
            ]);

            return $this->success([
                'payout_method'           => 'paystack',
                'paystack_recipient_code' => $recipientCode,
                'bank_name'               => $bankName,
                'account_name'            => $validated['account_name'],
                'account_number'          => $validated['account_number'],
                'bank_code'               => $validated['bank_code'],
            ], 'Nigerian bank account registered successfully. Payouts will be sent to this account.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * List available Nigerian banks (for the app's bank-code dropdown).
     */
    public function banks(): JsonResponse
    {
        $banks = $this->paystack->listBanks();
        return $this->success($banks, 'Nigerian banks retrieved.');
    }

    /**
     * Get the author's current payout setup.
     */
    public function payoutInfo(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'payout_method'           => $user->payout_method,
            'paystack_recipient_code' => $user->paystack_recipient_code,
            'bank_name'               => $user->bank_name,
            'bank_account_name'       => $user->bank_account_name,
            'bank_account_number'     => $user->bank_account_number ? '****' . substr($user->bank_account_number, -4) : null,
            'bank_code'               => $user->bank_code,
            'stripe_connected'        => ! empty($user->kyc_account_id),
        ], 'Payout info retrieved.');
    }

    /**
     * Switch payout method to Stripe (for authors who have both connected).
     */
    public function switchToStripe(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->kyc_account_id)) {
            return $this->error('You have not connected a Stripe account. Complete Stripe onboarding first.', 422);
        }

        $user->update(['payout_method' => 'stripe']);

        return $this->success(['payout_method' => 'stripe'], 'Payout method switched to Stripe (USD).');
    }
}
