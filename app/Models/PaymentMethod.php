<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    //
    use HasFactory/* , SoftDeletes */;

    protected $fillable = [
        'user_id',
        'provider', // stripe, razorpay, etc.
        'provider_payment_method_id',
        'type', // card, bank
        'default',
        'country_code',
        'purpose', // payment or payout
        'payment_method_data',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'payment_method_data' => 'array',
        ];
    }

    // One-to-many relationship: PaymentMethod belongs to a User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
