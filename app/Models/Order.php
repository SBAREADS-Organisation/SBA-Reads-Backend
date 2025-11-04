<?php

namespace App\Models;

use App\Services\Dashboard\DashboardCacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    public static function generateTrackingNumber()
    {
        return 'TRK-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(6));
    }

    protected $fillable = [
        'user_id',
        'total_amount',
        'platform_fee_amount',
        'status',
        'payout_status',
        'transaction_id',
        'tracking_number',
        'delivery_address_id',
        'delivered_at',
        'currency'
    ];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'string',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    public function deliveryAddress()
    {
        return $this->belongsTo(Address::class);
    }

    protected static function booted()
    {
        static::created(function ($order) {
            DashboardCacheService::clearAdminDashboard();
        });

        static::updated(function ($order) {
            DashboardCacheService::clearAdminDashboard();
            foreach ($order->items as $item) {
                foreach ($item->book->authors as $author) {
                    DashboardCacheService::clearAuthorDashboard($author->id);
                }
            }
        });
    }
}
