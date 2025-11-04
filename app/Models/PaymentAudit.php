<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentAudit extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'transaction_id',
        'transaction_reference',
        'total_amount',
        'authors_pay',
        'company_pay',
        'vat_amount',
        'currency',
        'payment_status',
        'processed_at',
        'audit_metadata',
    ];

    protected $casts = [
        'audit_metadata' => 'array',
        'id' => 'string',
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
}
