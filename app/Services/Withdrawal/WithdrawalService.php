<?php

namespace App\Services\Withdrawal;

use App\Models\Withdrawal;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class WithdrawalService
{
    use ApiResponse;

    protected PaymentService $paymentService;
    protected StripeClient $stripe;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Initiate a new withdrawal request
     *
     * @param User $user
     * @param array $data
     * @return Withdrawal
     * @throws \Exception
     */
    public function initiateWithdrawal(User $user, array $data): Withdrawal
    {
        // Validate user has sufficient balance
        if ($user->wallet_balance < $data['amount']) {
            throw new \Exception('Insufficient wallet balance for withdrawal');
        }

        // Validate minimum withdrawal amount
        if ($data['amount'] < 1.00) {
            throw new \Exception('Minimum withdrawal amount is $1.00');
        }

        // Create withdrawal record
        $reference = uniqid('wd_' . '_');

        $withdrawal = Withdrawal::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'reference' => $reference,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'usd',
            'withdrawal_method' => $data['withdrawal_method'] ?? 'bank_transfer',
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'description' => $data['description'] ?? 'Withdrawal to bank account',
            'meta_data' => [
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'withdrawal_method' => $data['withdrawal_method'] ?? 'bank_transfer',
            ],
            'status' => 'pending',
        ]);

        // Process Stripe transfer
        $this->processStripeTransfer($withdrawal, $user);

        return $withdrawal;
    }

    /**
     * Process Stripe transfer for withdrawal
     *
     * @param Withdrawal $withdrawal
     * @param User $user
     * @return void
     * @throws \Exception
     */
    protected function processStripeTransfer(Withdrawal $withdrawal, User $user): void
    {
        try {
            // Ensure user has connected Stripe account
            if (empty($user->kyc_account_id)) {
                throw new \Exception('User does not have a connected Stripe account');
            }

            // Convert amount to cents for Stripe
            $amountInCents = $this->paymentService->convertToSubunit($withdrawal->amount, $withdrawal->currency);

            // Create Stripe transfer
            $transfer = $this->stripe->transfers->create([
                'amount' => $amountInCents,
                'currency' => $withdrawal->currency,
                'destination' => $user->kyc_account_id,
                'transfer_group' => 'withdrawal_' . $withdrawal->id,
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'user_id' => $user->id,
                    'type' => 'withdrawal',
                ],
            ]);

            // Update withdrawal with transfer details
            $withdrawal->markAsSucceeded($transfer->id, [
                'transfer_id' => $transfer->id,
                'amount' => $withdrawal->amount,
                'currency' => $withdrawal->currency,
                'destination' => $user->kyc_account_id,
            ]);

            // Update user wallet balance
            $user->decrement('wallet_balance', $withdrawal->amount);
        } catch (ApiErrorException $e) {
            $withdrawal->markAsFailed($e->getMessage());
            throw new \Exception('Stripe transfer failed: ' . $e->getMessage());
        }
    }

    /**
     * Get user's withdrawal history
     *
     * @param User $user
     * @param array $filters
     * @return mixed
     */
    public function getUserWithdrawals(User $user, array $filters = [])
    {
        $query = Withdrawal::query()
            ->where('user_id', $user->id)
            ->with(['user'])
            ->orderBy('created_at', 'desc');

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get specific withdrawal details
     *
     * @param User $user
     * @param string $id
     * @return Withdrawal|null
     */
    public function getWithdrawalDetails(User $user, string $id): ?Withdrawal
    {
        return Withdrawal::where('user_id', $user->id)
            ->where('id', $id)
            ->with(['user'])
            ->first();
    }

    /**
     * Calculate withdrawal fees
     *
     * @param float $amount
     * @return float
     */
    public function calculateWithdrawalFee(float $amount): float
    {
        // Fixed fee + percentage
        $fixedFee = 0.25; // $0.25
        $percentageFee = 0.01; // 1%

        return $fixedFee + ($amount * $percentageFee);
    }

    /**
     * Validate withdrawal amount
     *
     * @param User $user
     * @param float $amount
     * @return bool
     * @throws \Exception
     */
    public function validateWithdrawalAmount(User $user, float $amount): bool
    {
        if ($amount <= 0) {
            throw new \Exception('Withdrawal amount must be greater than zero');
        }

        if ($amount > $user->wallet_balance) {
            throw new \Exception('Insufficient wallet balance');
        }

        if ($amount < 1.00) {
            throw new \Exception('Minimum withdrawal amount is $1.00');
        }

        return true;
    }
}
