<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingProgress extends Model
{
    /** @use HasFactory<\Database\Factories\ReadingProgressFactory> */
    use HasFactory;

    // Fillable
    protected $fillable = [
        'user_id',
        'book_id',
        'progress',
        'last_accessed',
        'page',
        'bookmarks',
        'session_duration',
    ];

    /**
     * Get the attributes that should be casted
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'bookmarks' => 'json',
            'session_duration' => 'json',
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
