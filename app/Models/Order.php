<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    public static function generateTrackingNumber()
    {
        return 'TRK-' . strtoupper(Str::random(4)) . '-' . time();
    }

    protected $fillable = [
        'user_id', 'total_amount', 'status', 'transaction_id',
        'tracking_number', 'delivery_address_id', 'delivered_at'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_id' => 'string',
        ];
    }

    public function user() { return $this->belongsTo(User::class); }

    public function items() { return $this->hasMany(OrderItem::class); }

    public function transaction() { return $this->belongsTo(Transaction::class, 'transaction_id', 'id'); }

    // public function purpose()
    // {
    //     return $this->morphOne(Transaction::class, 'purpose');
    // }

    public function deliveryAddress() { return $this->belongsTo(Address::class); }
}
