<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A time a coach is free. Held for a buyer while they pay, then booked. */
class CreatorSlot extends Model
{
    protected $fillable = ['product_id', 'starts_at', 'purchase_id', 'held_until', 'booked_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'held_until' => 'datetime',
            'booked_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CreatorProduct::class, 'product_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreatorPurchase::class, 'purchase_id');
    }

    /** Future times nobody has booked or is in the middle of paying for. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('booked_at')
            ->where('starts_at', '>', now())
            ->where(fn ($q) => $q->whereNull('held_until')->orWhere('held_until', '<', now()));
    }

    public function isOpenFor(?CreatorPurchase $purchase = null): bool
    {
        if ($this->booked_at || $this->starts_at->isPast()) {
            return false;
        }

        return ! $this->held_until || $this->held_until->isPast() || ($purchase && (int) $this->purchase_id === (int) $purchase->id);
    }
}
