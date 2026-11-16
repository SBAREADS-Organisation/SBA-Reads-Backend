<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorProduct extends Model
{
    public const TYPES = ['course', 'download'];

    public const STATUSES = ['draft', 'published', 'hidden'];

    protected $fillable = [
        'store_id', 'user_id', 'type', 'title', 'slug', 'summary', 'description',
        'cover_url', 'price', 'currency', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(CreatorStore::class, 'store_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(CreatorLesson::class, 'product_id')->orderBy('position')->orderBy('id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(CreatorPurchase::class, 'product_id');
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }

    public function ownedBy(?User $user): bool
    {
        return $user && (int) $this->user_id === (int) $user->id;
    }

    public function purchasedBy(?User $user): bool
    {
        return $user && $this->purchases()->where('buyer_id', $user->id)->where('status', 'paid')->exists();
    }
}
