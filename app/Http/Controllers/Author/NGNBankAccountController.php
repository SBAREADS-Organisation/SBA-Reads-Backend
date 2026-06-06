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
            $recipientCode = $this->paystack->createRecipient(
                $validated['account_name'],
                $validated['account_number'],
                $validated['bank_code'],
            );

            $user->update(['paystack_recipient_code' => $recipientCode]);

            return $this->success([
                'paystack_recipient_code' => $recipientCode,
                'account_name'            => $validated['account_name'],
                'account_number'          => $validated['account_number'],
                'bank_code'               => $validated['bank_code'],
            ], 'Nigerian bank account registered for NGN payouts.');
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
}
