<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Someone on a store's email list: a buyer, or a visitor who signed up. */
class CreatorSubscriber extends Model
{
    protected $fillable = ['store_id', 'email', 'name', 'source', 'token', 'unsubscribed_at'];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }

    /** Adds someone to a store's list. People who unsubscribed stay off unless they sign up again themselves. */
    public static function add(int $storeId, string $email, ?string $name, string $source): self
    {
        $email = mb_strtolower(trim($email));
        $existing = static::where('store_id', $storeId)->where('email', $email)->first();
        if ($existing) {
            if ($source === 'signup' && $existing->unsubscribed_at) {
                $existing->update(['unsubscribed_at' => null]);
            }

            return $existing;
        }

        return static::create([
            'store_id' => $storeId, 'email' => $email, 'name' => $name, 'source' => $source, 'token' => Str::random(40),
        ]);
    }
}
