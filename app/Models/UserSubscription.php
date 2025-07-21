<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSubscription extends Model
{
    //
    protected $fillable = [
        'user_id', 'subscription_id', /* 'stripe_subscription_id', */ 'starts_at', 'ends_at', 'status',
    ];

    protected $dates = [
        'starts_at', 'ends_at',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isExpired()
    {
        return now()->greaterThan($this->ends_at);
    }
}
