<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorProduct extends Model
{
    public const TYPES = ['course', 'download', 'event', 'coaching', 'membership'];

    public const STATUSES = ['draft', 'published', 'hidden'];

    protected $fillable = [
        'store_id', 'user_id', 'type', 'title', 'slug', 'summary', 'description',
        'cover_url', 'price', 'currency', 'status', 'published_at',
        'starts_at', 'ends_at', 'timezone', 'location', 'online_url', 'capacity', 'duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'published_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'duration_minutes' => 'integer',
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

    public function slots(): HasMany
    {
        return $this->hasMany(CreatorSlot::class, 'product_id')->orderBy('starts_at');
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }

    public function isEvent(): bool
    {
        return $this->type === 'event';
    }

    public function isCoaching(): bool
    {
        return $this->type === 'coaching';
    }

    public function isMembership(): bool
    {
        return $this->type === 'membership';
    }

    /** Products bought once and kept. Coaching can be booked again and memberships renewed. */
    public function isOneOff(): bool
    {
        return ! $this->isCoaching() && ! $this->isMembership();
    }

    public function ownedBy(?User $user): bool
    {
        return $user && (int) $this->user_id === (int) $user->id;
    }

    /** Whether the person has access now. A membership only counts while it is paid up. */
    public function purchasedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $paid = $this->purchases()->where('buyer_id', $user->id)->where('status', 'paid');

        return $this->isMembership()
            ? $paid->where('access_until', '>', now())->exists()
            : $paid->exists();
    }

    /** When the person's membership runs out, if they have ever joined. */
    public function memberUntil(?User $user): ?\Illuminate\Support\Carbon
    {
        if (! $user || ! $this->isMembership()) {
            return null;
        }
        $until = $this->purchases()->where('buyer_id', $user->id)->where('status', 'paid')->max('access_until');

        return $until ? \Illuminate\Support\Carbon::parse($until) : null;
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
