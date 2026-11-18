<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A buyer's card as Paystack can charge it again (for renewals and instalments). Never the card number. */
class CreatorSavedCard extends Model
{
    protected $fillable = ['user_id', 'authorization_code', 'signature', 'email', 'last4', 'brand', 'exp_month', 'exp_year'];

    protected $hidden = ['authorization_code'];

    protected function casts(): array
    {
        return ['authorization_code' => 'encrypted'];
    }

    public function label(): string
    {
        return trim(ucfirst((string) $this->brand) . ' ending ' . $this->last4);
    }
}
