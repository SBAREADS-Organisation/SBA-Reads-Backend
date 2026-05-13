<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioBookPurchase extends Model
{
    protected $fillable = [
        'user_id',
        'book_id',
        'author_id',
        'price',
        'author_payout_amount',
        'platform_fee_amount',
        'price_converted',
        'currency',
        'status',
        'payout_status',
        'payment_provider',
        'payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'price'                => 'float',
            'author_payout_amount' => 'float',
            'platform_fee_amount'  => 'float',
            'price_converted'      => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
