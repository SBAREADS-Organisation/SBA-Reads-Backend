<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorStore extends Model
{
    protected $fillable = [
        'user_id', 'name', 'slug', 'bio', 'logo_url', 'fee_payer',
        'plan', 'plan_paid_until', 'trial_ends_at', 'suspended',
    ];

    protected function casts(): array
    {
        return [
            'plan_paid_until' => 'datetime',
            'trial_ends_at' => 'datetime',
            'suspended' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(CreatorProduct::class, 'store_id');
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /** The plan whose fees apply right now: a paid plan, the free trial, or Starter. */
    public function activePlan(): string
    {
        if ($this->plan !== 'starter' && $this->plan_paid_until && $this->plan_paid_until->isFuture()) {
            return $this->plan;
        }

        return $this->onTrial() ? config('creators.trial_plan') : 'starter';
    }

    public function feeRate(string $currency = 'NGN'): float
    {
        $plan = config('creators.plans.' . $this->activePlan());

        return strtoupper($currency) === 'NGN' ? $plan['fee_ngn'] : $plan['fee_foreign'];
    }
}
