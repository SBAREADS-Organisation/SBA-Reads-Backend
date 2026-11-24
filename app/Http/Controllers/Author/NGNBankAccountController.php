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
            if (! self::nameMatches($request->user(), $accountName)) {
                return $this->error("The name on this account ({$accountName}) doesn't match your verified name. "
                    . 'Payouts can only go to an account in your own name.', 422);
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
            // The account holder's name comes from the bank (through Paystack), never from the app.
            $accountName = trim((string) $this->paystack->resolveAccount($validated['account_number'], $validated['bank_code']));
            if ($accountName === '') {
                return $this->error('Account not found. Please check the account number and bank.', 422);
            }

            // Payouts only go to an account in the author's own (verified) name.
            if (! self::nameMatches($user, $accountName)) {
                return $this->error("The name on this account ({$accountName}) doesn't match your verified name. "
                    . 'Payouts can only go to an account in your own name. If this is wrong, contact support@sbareads.com.', 422);
            }

            // Resolve bank name from code for display purposes
            $banks    = $this->paystack->listBanks();
            $bankName = collect($banks)->firstWhere('code', $validated['bank_code'])['name'] ?? $validated['bank_code'];

            $recipientCode = $this->paystack->createRecipient(
                $accountName,
                $validated['account_number'],
                $validated['bank_code'],
            );

            // Changing an existing account holds withdrawals for a while, so someone who gets into
            // the account can't redirect and withdraw the money straight away.
            $isChange = ! empty($user->paystack_recipient_code)
                && ($user->bank_account_number !== $validated['account_number'] || $user->bank_code !== $validated['bank_code']);
            $holdUntil = $isChange ? now()->addHours((int) config('payments.bank_change_hold_hours', 48)) : null;

            $user->update([
                'paystack_recipient_code' => $recipientCode,
                'payout_method'           => 'paystack',
                'bank_name'               => $bankName,
                'bank_account_name'       => $accountName,
                'bank_account_number'     => $validated['account_number'],
                'bank_code'               => $validated['bank_code'],
            ]);
            if ($holdUntil) {
                $user->forceFill(['payout_hold_until' => $holdUntil])->save();
            }

            $this->tellAuthor($user, $bankName, $validated['account_number'], $isChange, $holdUntil);

            return $this->success([
                'payout_method'           => 'paystack',
                'paystack_recipient_code' => $recipientCode,
                'bank_name'               => $bankName,
                'account_name'            => $accountName,
                'account_number'          => $validated['account_number'],
                'bank_code'               => $validated['bank_code'],
                'withdrawals_open_at'     => $holdUntil?->toISOString(),
            ], $holdUntil
                ? 'Bank account changed. For your security, withdrawals open again on ' . $holdUntil->format('j M, g:i a') . '.'
                : 'Nigerian bank account registered successfully. Payouts will be sent to this account.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Once an author's identity is verified, the bank account must be in their name: their verified
     * first or last name has to appear in the account name (banks order and abbreviate names differently).
     */
    public static function nameMatches($user, string $accountName): bool
    {
        if ($user->kyc_status !== 'verified') {
            return true;
        }
        $words = fn (?string $s) => array_filter(preg_split('/[^A-Z]+/', strtoupper((string) $s)), fn ($w) => strlen($w) >= 2);
        $verified = array_merge($words($user->first_name), $words($user->last_name));
        if (! $verified) {
            return true;
        }

        return (bool) array_intersect($verified, $words($accountName));
    }

    /** Email and notify the author whenever their payout account is added or changed. */
    private function tellAuthor($user, string $bankName, string $number, bool $isChange, $holdUntil): void
    {
        $masked = '****' . substr($number, -4);
        $title = $isChange ? 'Your payout bank account was changed' : 'Payout bank account added';
        $body = ($isChange ? "Your SBA Reads payout account was changed to {$bankName} {$masked}." : "Your SBA Reads earnings will be paid to {$bankName} {$masked}.")
            . ($holdUntil ? "\n\nFor your security, withdrawals are paused until " . $holdUntil->format('j F Y, g:i a') . '.' : '')
            . "\n\nIf you didn't do this, contact support@sbareads.com straight away so we can secure your account.";
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Mail\Generic\GenericAppNotification($title, $body));
            app(\App\Services\Notification\NotificationService::class)->send($user, $title, $body, ['in-app']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Payout account notice failed: ' . $e->getMessage());
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
