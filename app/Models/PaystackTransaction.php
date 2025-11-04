<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaystackTransaction extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'transaction_id',
        'user_id',
        'paystack_reference',
        'paystack_transaction_id',
        'paystack_authorization_code',
        'paystack_customer_code',
        'paystack_plan_code',
        'paystack_subscription_code',
        'amount_kobo',
        'amount_naira',
        'fees_kobo',
        'currency',
        'gateway_response',
        'channel',
        'ip_address',
        'customer_email',
        'customer_name',
        'customer_phone',
        'status',
        'paid_at',
        'metadata',
        'paystack_response',
    ];

    protected $casts = [
        'id' => 'string',
        'amount_kobo' => 'decimal:2',
        'amount_naira' => 'decimal:2',
        'fees_kobo' => 'decimal:2',
        'paid_at' => 'datetime',
        'metadata' => 'array',
        'paystack_response' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
