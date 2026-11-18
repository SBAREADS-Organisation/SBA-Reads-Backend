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
        'instalments_max', 'upsell_product_id', 'upsell_price', 'bonus_book_id',
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
            'instalments_max' => 'integer',
            'upsell_price' => 'decimal:2',
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

    public function upsellProduct(): BelongsTo
    {
        return $this->belongsTo(CreatorProduct::class, 'upsell_product_id');
    }

    public function bonusBook(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'bonus_book_id');
    }

    /** The checkout offer, when the other product is still on sale in the same store. */
    public function activeUpsell(): ?CreatorProduct
    {
        $offer = $this->upsell_product_id ? $this->upsellProduct : null;

        return $offer && $offer->status === 'published' && (int) $offer->store_id === (int) $this->store_id && $this->upsell_price !== null
            ? $offer : null;
    }

    /** Only courses can be paid in instalments, and only above a minimum price. */
    public function allowsInstalments(): bool
    {
        return $this->type === 'course' && (int) $this->instalments_max >= 2
            && (float) $this->price >= (float) config('creators.instalment_min_ngn');
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
        // A course whose instalments stopped being paid is locked until the buyer catches up.
        $paid = $this->purchases()->where('buyer_id', $user->id)->where('status', 'paid')
            ->where(fn ($q) => $q->whereNull('instalment_status')->orWhere('instalment_status', '!=', 'defaulted'));

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
