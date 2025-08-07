<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Dashboard\DashboardCacheService;


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
        'description',
        'payment_provider',
        'purchased_by',
        'direction',
        'available_at',
        'payout_data',
        'purpose_type',
        'purpose_id',
        'meta_data',
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
}
