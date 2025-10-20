<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'book_id',
        'author_id',
        'quantity',
        'unit_price',
        'total_price',
        'author_payout_amount',
        'platform_fee_amount',
        'payout_status', // 'pending', 'paid', 'failed'
        'payout_error', // Error message if payout fails
        'total_price_usd',
        'author_payout_amount_usd',
        'platform_fee_amount_usd',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'total_price' => 'float',
        'author_payout_amount' => 'float',
        'platform_fee_amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }


    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
