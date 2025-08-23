<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAudit extends Model
{
    use HasFactory;

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

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}