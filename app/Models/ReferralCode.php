<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralCode extends Model
{
    protected $fillable = ['code', 'label', 'created_by', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function signups()
    {
        return $this->hasMany(User::class, 'referred_by_code', 'code');
    }
}
