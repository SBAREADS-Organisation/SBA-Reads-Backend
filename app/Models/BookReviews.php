<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookReviews extends Model
{
    /** @use HasFactory<\Database\Factories\BookReviewsFactory> */
    use HasFactory;

    // Fillable
    protected $fillable = [
        'user_id',
        'book_id',
        'rating',
        'comment',
        'approved',
    ];

    /**
     * Get the attributes that should be casted
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'approved' => 'boolean',
        ];
    }

    // user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // book
    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
