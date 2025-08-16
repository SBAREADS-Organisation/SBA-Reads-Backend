<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\Dashboard\DashboardCacheService;

class Book extends Model
{
    /** @use HasFactory<\Database\Factories\BookFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'sub_title',
        'slug',
        'author_id',
        'description',
        'isbn',
        'table_of_contents',
        'tags',
        'category',
        'genres',
        'publication_date',
        'language',
        'cover_image',
        'format',
        'files',
        'target_audience',
        'pricing',
        'actual_price',
        'discounted_price',
        'currency',
        'availability',
        'views_count',
        'file_size',
        'archived',
        'deleted',
        'drm_info',
        'meta_data',
        'publisher',
        'visibility',
        'status',
        'approved_at',
        'approved_by',
        'review_notes',
        'rejection_note',
        'expired_at',
    ];

    /**
     * Get the attributes that should be casted
     *
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'table_of_contents' => 'array',
            'tags' => 'array',
            'category' => 'array',
            'genres' => 'array',
            'publication_date' => 'date',
            'approved_at' => 'date',
            'expired_at' => 'date',
            'language' => 'array',
            'cover_image' => 'json',
            'files' => 'array',
            'target_audience' => 'array',
            'pricing' => 'json',
            'availability' => 'array',
            'actual_price' => 'float',
            'discounted_price' => 'float',
            'drm_info' => 'json',
            'meta_data' => 'json',
            'archived' => 'boolean',
            'deleted' => 'boolean',
        ];
    }

    public function media()
    {
        return $this->morphMany(MediaUpload::class, 'mediable');
    }

    public function coverImage()
    {
        return $this->morphOne(MediaUpload::class, 'mediable')->where('context', 'book_cover');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function authors()
    {
        return $this->belongsToMany(User::class, 'book_authors', 'book_id', 'author_id');
    }

    // Bookmarks
    public function bookmarkedBy()
    {
        return $this->belongsToMany(User::class, 'book_user_bookmarks', 'book_id', 'user_id')->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'book_categories');
    }

    public function purchasers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'book_user', 'book_id', 'user_id')->withTimestamps();
    }

    // public function files()
    // {
    //     return $this->hasMany(BookFile::class);
    // }

    public function reviews()
    {
        return $this->hasMany(BookReviews::class);
    }

    // public function pricing()
    // {
    //     return $this->belongsTo(BookPricing::class);
    // }

    // Reading Progess records
    public function readingProgress()
    {
        return $this->hasMany(ReadingProgress::class);
    }

    // Analytics records
    public function analytics()
    {
        return $this->hasOne(BookMetaDataAnalytics::class);
        // return $this->hasMany(BookMetaDataAnalytics::class);
    }

    protected static function booted()
    {
        static::created(function ($book) {
            DashboardCacheService::clearAdminDashboard();
            foreach ($book->authors as $author) {
                DashboardCacheService::clearAuthorDashboard($author->id);
            }
        });

        static::updated(function ($book) {
            DashboardCacheService::clearAdminDashboard();
            foreach ($book->authors as $author) {
                DashboardCacheService::clearAuthorDashboard($author->id);
            }
        });
    }
}
