<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookMetaDataAnalytics extends Model
{
    /** @use HasFactory<\Database\Factories\BookMetaDataAnalyticsFactory> */
    use HasFactory;

    // Fillable
    protected $fillable = [
        'book_id',
        'views',
        'downloads',
        'purchases',
        'favourites',
        'bookmarks',
        'reads',
        'shares',
        'likes',
    ];

    /**
     * Get the attributes that should be casted
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'views' => 'integer',
            'downloads' => 'integer',
            'shares' => 'integer',
            'likes' => 'integer',
        ];
    }

    public function book() {
        return $this->belongsTo(Book::class);
    }
}
