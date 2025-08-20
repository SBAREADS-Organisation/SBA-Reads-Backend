<?php

namespace App\Services\Payments;

use App\Models\Transaction;
use App\Models\PaymentAudit;
use Illuminate\Support\Facades\Log;

class PaymentAuditService
{
    /**
     * Create a payment audit record for a transaction
     */
    public function createAuditRecord(Transaction $transaction, array $auditData): PaymentAudit
    {
        try {
            return PaymentAudit::create([
                'transaction_id' => $transaction->id,
                'transaction_reference' => $transaction->reference,
                'total_amount' => $auditData['total_amount'] ?? $transaction->amount,
                'authors_pay' => $auditData['authors_pay'] ?? 0,
                'company_pay' => $auditData['company_pay'] ?? 0,
                'vat_amount' => $auditData['vat_amount'] ?? 0,
                'currency' => $transaction->currency,
                'payment_status' => $transaction->status,
                'processed_at' => now(),
                'audit_metadata' => $auditData['metadata'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create payment audit record', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'audit_data' => $auditData
            ]);
            throw $e;
        }
    }

    /**
     * Update payment audit record when transaction status changes
     */
    public function updateAuditStatus(Transaction $transaction): void
    {
        $audit = PaymentAudit::where('transaction_id', $transaction->id)->first();

        if ($audit) {
            $audit->update([
                'payment_status' => $transaction->status,
                'processed_at' => now(),
            ]);
        }
    }

    /**
     * Get audit records for a specific transaction
     */
    public function getTransactionAudit(string $transactionId): ?PaymentAudit
    {
        return PaymentAudit::where('transaction_id', $transactionId)->first();
    }

    /**
     * Get audit records by date range
     */
    public function getAuditByDateRange(\DateTime $startDate, \DateTime $endDate)
    {
        return PaymentAudit::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Calculate total VAT for a date range
     */
    public function calculateTotalVat(\DateTime $startDate, \DateTime $endDate): float
    {
        return PaymentAudit::whereBetween('created_at', [$startDate, $endDate])
            ->where('payment_status', 'succeeded')
            ->sum('vat_amount');
    }

    /**
     * Calculate total author payouts for a date range
     */
    public function calculateTotalAuthorPayouts(\DateTime $startDate, \DateTime $endDate): float
    {
        return PaymentAudit::whereBetween('created_at', [$startDate, $endDate])
            ->where('payment_status', 'succeeded')
            ->sum('authors_pay');
    }

    /**
     * Calculate total company revenue for a date range
     */
    public function calculateTotalCompanyRevenue(\DateTime $startDate, \DateTime $endDate): float
    {
        return PaymentAudit::whereBetween('created_at', [$startDate, $endDate])
            ->where('payment_status', 'succeeded')
            ->sum('company_pay');
    }
}
