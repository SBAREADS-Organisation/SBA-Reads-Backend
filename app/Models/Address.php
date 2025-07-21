<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    /** @use HasFactory<\Database\Factories\AddressFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'full_name', 'address', 'city',
        'region', 'country', 'postal_code', 'phone_number', 'is_default',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
