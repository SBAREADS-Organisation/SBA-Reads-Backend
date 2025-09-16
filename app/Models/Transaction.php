<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\Stripe\StripeWebhookService;

class Transaction extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';
    // Add transaction_id from Transaction  model

    protected $fillable = [
        'id',
        'user_id',
        'reference',
        'payment_intent_id',
        'payment_client_secret',
        'status',
        'currency',
        'type',
        'amount',
        'amount_usd',
        'amount_naira',
        'exchange_rate',
        'description',
        'payment_provider',
        'purchased_by',
        'direction',
        'available_at',
        'payout_data',
        'purpose_type',
        'purpose_id',
        'meta_data',
        'paystack_reference',
        'paystack_authorization_code',
        'paystack_response',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta_data' => 'array',
            'id' => 'string',
        ];
    }

    // protected $casts = [
    //     'meta_data' => 'array',
    // ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function purpose()
    {
        return $this->morphTo();
    }

    protected static function booted()
    {
        static::created(function ($transaction) {
            DashboardCacheService::clearAdminDashboard();
            if ($transaction->user_id) {
                DashboardCacheService::clearAuthorDashboard($transaction->user_id);
            }
        });

        static::updated(function ($transaction) {
            DashboardCacheService::clearAdminDashboard();
            if ($transaction->user_id) {
                DashboardCacheService::clearAuthorDashboard($transaction->user_id);
            }
        });
    }

    public function checkStripeStatus()
    {
        if (!$this->payment_intent_id) {
            return false;
        }

        try {
            $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
            $paymentIntent = $stripe->paymentIntents->retrieve($this->payment_intent_id);



            $isSuccessful = $paymentIntent->status === 'succeeded';

            if ($isSuccessful && $this->status !== 'succeeded') {


                $this->update(['status' => 'succeeded']);
                $this->processSuccessfulPayment();
            }

            return $isSuccessful;
        } catch (\Exception $e) {

            return false;
        }
    }

    protected function processSuccessfulPayment()
    {
        try {
            $webhookService = app(\App\Services\Stripe\StripeWebhookService::class);



            if ($this->purpose_type === 'digital_book_purchase') {
                $webhookService->processSuccessfulDigitalBookPurchase($this, $this->user);
            } elseif ($this->purpose_type === 'order') {
                $webhookService->processSuccessfulOrder($this, $this->user);
            }
        } catch (\Exception $e) {

            throw $e;
        }
    }
}
