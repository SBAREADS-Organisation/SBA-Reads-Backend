<?php

namespace App\Http\Controllers\Withdrawal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\StripePayoutRequest;
use Illuminate\Http\Request;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse; 

class StripeWithdrawalController extends Controller
{
    protected StripeConnectService $stripeConnectService;

    public function __construct()
    {
        $this->stripeConnectService = new StripeConnectService();
    }
    public function getAuthorAccountBalance(Request $request)
    {
        try {
            $user = $request->user();
            $stripeAccountId = $user->kyc_account_id;

            // check if stripeAccountId exists
            if (!$stripeAccountId) {
                return response()->json([
                    'code' => 400,
                    'data' => null,
                    'message' => null,
                    'error' => 'User does not have a connected Stripe account.',
                ], 400);
            }
            $response = $this->stripeConnectService->retrieveAccountBalance($stripeAccountId);
            return $response; // Already a JsonResponse via ApiResponse trait
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'data' => null,
                'message' => null,
                'error' => 'Failed to retrieve account balance: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function initiateStripePayout(StripePayoutRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $stripeAccountId = $user->kyc_account_id;

            // check if stripeAccountId exists
            if (!$stripeAccountId) {
                return response()->json([
                    'code' => 400,
                    'data' => null,
                    'message' => null,
                    'error' => 'User does not have a connected Stripe account.',
                ], 400);
            }

            $amount = $request->input('amount');
            $currency = $request->input('currency');

            $response = $this->stripeConnectService->createPayout($stripeAccountId, $amount, $currency);
            return $response; // Already a JsonResponse via ApiResponse trait
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'data' => null,
                'message' => null,
                'error' => 'Failed to initiate payout: ' . $e->getMessage(),
            ], 500);
        }
    }
}
