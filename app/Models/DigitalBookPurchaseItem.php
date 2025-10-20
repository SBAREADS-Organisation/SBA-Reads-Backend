<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalBookPurchaseItem extends Model
{
    protected $fillable = [
        'digital_book_purchase_id',
        'book_id',
        'author_id',
        'quantity',
        'price_at_purchase',
        'author_payout_amount',
        'platform_fee_amount',
        'price_at_purchase_usd',
        'author_payout_amount_usd',
        'platform_fee_amount_usd',
        'payout_status',
        'payout_error',
        'stripe_transfer_id',
        'currency'
    ];

    protected $casts = [
        'price_at_purchase' => 'float',
        'author_payout_amount' => 'float',
        'platform_fee_amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function digitalBookPurchase(): BelongsTo
    {
        return $this->belongsTo(DigitalBookPurchase::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
