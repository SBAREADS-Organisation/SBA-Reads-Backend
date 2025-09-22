<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\Payments\PaymentService;

class StripePayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'stripe_payout_id',
        'amount',
        'currency',
        'status',
        'destination',
        'destination_type',
        'arrival_date',
        'description',
        'failure_code',
        'failure_message',
        'statement_descriptor',
        'source_type',
        'automatic',
        'created_stripe',
        'metadata',
        'stripe_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'arrival_date' => 'integer', // Unix timestamp
        'created_stripe' => 'integer', // Unix timestamp
        'automatic' => 'boolean',
        'metadata' => 'array',
        'stripe_response' => 'array',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        // Clear dashboard cache when payout is updated
        static::updated(function ($payout) {
            if ($payout->user) {
                app(DashboardCacheService::class)->clearAuthorDashboard($payout->user->id);
            }
        });

        static::created(function ($payout) {
            if ($payout->user) {
                app(DashboardCacheService::class)->clearAuthorDashboard($payout->user->id);
            }
        });
    }

    /**
     * Get the user that owns the payout.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for filtering by status
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for filtering by user
     */
    public function scopeUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for filtering by currency
     */
    public function scopeCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope for filtering by destination type
     */
    public function scopeDestinationType($query, $type)
    {
        return $query->where('destination_type', $type);
    }

    /**
     * Scope for filtering by date range using arrival_date
     */
    public function scopeArrivalDateRange($query, $startTimestamp, $endTimestamp)
    {
        return $query->whereBetween('arrival_date', [$startTimestamp, $endTimestamp]);
    }

    /**
     * Scope for automatic payouts
     */
    public function scopeAutomatic($query, $automatic = true)
    {
        return $query->where('automatic', $automatic);
    }

    /**
     * Check if payout is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payout is in transit
     */
    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    /**
     * Check if payout is paid/successful
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Check if payout has failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if payout is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'canceled';
    }

    /**
     * Get the formatted amount with currency
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Get the formatted net amount with currency
     */
    public function getFormattedNetAmountAttribute(): string
    {
        return number_format($this->net_amount, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Get the formatted fee amount with currency
     */
    public function getFormattedFeeAmountAttribute(): string
    {
        return number_format($this->fee_amount, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Get human readable arrival date
     */
    public function getArrivalDateFormattedAttribute(): string
    {
        return $this->arrival_date ? date('Y-m-d H:i:s', $this->arrival_date) : 'N/A';
    }

    /**
     * Get human readable created date from Stripe
     */
    public function getCreatedStripeFormattedAttribute(): string
    {
        return $this->created_stripe ? date('Y-m-d H:i:s', $this->created_stripe) : 'N/A';
    }

    /**
     * Check if payout can be cancelled (only pending payouts can be cancelled)
     */
    public function canBeCancelled(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Get the destination display name based on type
     */
    public function getDestinationDisplayAttribute(): string
    {
        switch ($this->destination_type) {
            case 'bank_account':
                return 'Bank Account (' . substr($this->destination, -4) . ')';
            case 'card':
                return 'Card (' . substr($this->destination, -4) . ')';
            default:
                return ucfirst(str_replace('_', ' ', $this->destination_type));
        }
    }

    /**
     * Update payout status from Stripe webhook
     */
    public function updateFromStripeWebhook(array $stripePayoutData): bool
    {
        return $this->update([
            'status' => $stripePayoutData['status'],
            'failure_code' => $stripePayoutData['failure_code'] ?? null,
            'failure_message' => $stripePayoutData['failure_message'] ?? null,
               'stripe_response' => $stripePayoutData,
        ]);
    }

    /**
     * Create payout from Stripe payout object
     */
    public static function createFromStripeObject(int $userId, array $stripePayout): self
    {
        return self::create([
            'user_id' => $userId,
            'stripe_payout_id' => $stripePayout['id'],
            'amount' => app(PaymentService::class)->convertFromSubunit($stripePayout['amount'], $stripePayout['currency']),
            'currency' => $stripePayout['currency'],
            'status' => $stripePayout['status'],
            'destination' => $stripePayout['destination'],
            'destination_type' => $stripePayout['type'] ?? 'bank_account',
            'arrival_date' => $stripePayout['arrival_date'],
            'description' => $stripePayout['description'] ?? null,
            'failure_code' => $stripePayout['failure_code'] ?? null,
            'failure_message' => $stripePayout['failure_message'] ?? null,
            'statement_descriptor' => $stripePayout['statement_descriptor'] ?? null,
            'source_type' => $stripePayout['source_type'] ?? 'card',
            'automatic' => $stripePayout['automatic'] ?? false,
            'created_stripe' => $stripePayout['created'],
            'metadata' => $stripePayout['metadata'] ?? [],
            'stripe_response' => $stripePayout,
        ]);
    }
}
