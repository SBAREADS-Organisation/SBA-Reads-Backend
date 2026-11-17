<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A discount code a creator gives out: a percentage or a fixed amount off. */
class CreatorDiscount extends Model
{
    protected $fillable = ['store_id', 'code', 'kind', 'value', 'product_id', 'max_uses', 'uses', 'expires_at', 'active'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'expires_at' => 'datetime',
            'active' => 'boolean',
            'uses' => 'integer',
            'max_uses' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CreatorProduct::class, 'product_id');
    }

    /** Why this code can't be used on the product, or null when it can. */
    public function problemFor(CreatorProduct $product): ?string
    {
        return match (true) {
            ! $this->active => 'This code is no longer active.',
            (int) $this->store_id !== (int) $product->store_id, $this->product_id && (int) $this->product_id !== (int) $product->id => 'This code does not apply to this product.',
            $this->expires_at && $this->expires_at->isPast() => 'This code has expired.',
            $this->max_uses !== null && $this->uses >= $this->max_uses => 'This code has been used up.',
            default => null,
        };
    }

    /** How much comes off the price. */
    public function amountOff(float $price): float
    {
        $off = $this->kind === 'percent' ? $price * min(100, (float) $this->value) / 100 : (float) $this->value;

        return round(min($price, max(0, $off)), 2);
    }
}
