<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioBookmark extends Model
{
    protected $fillable = [
        'user_id',
        'book_id',
        'segment_idx',
        'position_ms',
        'chapter_idx',
        'chapter_title',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'segment_idx' => 'integer',
            'position_ms' => 'integer',
            'chapter_idx' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
