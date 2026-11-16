<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorProduct extends Model
{
    public const TYPES = ['course', 'download', 'event'];

    public const STATUSES = ['draft', 'published', 'hidden'];

    protected $fillable = [
        'store_id', 'user_id', 'type', 'title', 'slug', 'summary', 'description',
        'cover_url', 'price', 'currency', 'status', 'published_at',
        'starts_at', 'ends_at', 'timezone', 'location', 'online_url', 'capacity',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'published_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
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

    public function isEvent(): bool
    {
        return $this->type === 'event';
    }

    public function ownedBy(?User $user): bool
    {
        return $user && (int) $this->user_id === (int) $user->id;
    }

    public function purchasedBy(?User $user): bool
    {
        return $user && $this->purchases()->where('buyer_id', $user->id)->where('status', 'paid')->exists();
    }

    /** Tickets still available, or null when the event has no limit. */
    public function spotsLeft(): ?int
    {
        if (! $this->isEvent() || ! $this->capacity) {
            return null;
        }

        return max(0, $this->capacity - $this->purchases()->where('status', 'paid')->count());
    }

    public function ticketSalesClosed(): bool
    {
        return $this->isEvent() && (($this->ends_at ?? $this->starts_at)?->isPast() ?? false);
    }
}
