<?php

namespace App\Http\Controllers\Withdrawal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\WithdrawalRequest;
use App\Services\Withdrawal\WithdrawalService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WithdrawalController extends Controller
{
    use ApiResponse;

    protected WithdrawalService $withdrawalService;

    public function __construct(WithdrawalService $withdrawalService)
    {
        $this->withdrawalService = $withdrawalService;
    }

    /**
     * Initiate a new withdrawal request
     *
     * @param WithdrawalRequest $request
     * @return JsonResponse
     */
    public function initiate(WithdrawalRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $withdrawal = $this->withdrawalService->initiateWithdrawal(
                $user,
                $request->validated()
            );

            return $this->success($withdrawal, 'Withdrawal initiated successfully', 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Get user's withdrawal history
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function history(Request $request): JsonResponse
    {
        $user = Auth::user();
        $withdrawals = $this->withdrawalService->getUserWithdrawals($user, $request->all());

        return $this->success($withdrawals, 'Withdrawal history retrieved successfully');
    }

    /**
     * Get specific withdrawal details
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $user = Auth::user();
        $withdrawal = $this->withdrawalService->getWithdrawalDetails($user, $id);

        if (!$withdrawal) {
            return $this->error('Withdrawal not found', 404);
        }

        return $this->success($withdrawal, 'Withdrawal details retrieved successfully');
    }
}
