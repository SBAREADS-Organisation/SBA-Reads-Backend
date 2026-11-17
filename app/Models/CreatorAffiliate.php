<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone who shares a store's products for a commission. */
class CreatorAffiliate extends Model
{
    protected $fillable = ['store_id', 'user_id', 'code'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(CreatorStore::class, 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
