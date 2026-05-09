<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookChapter extends Model
{
    protected $fillable = [
        'book_id',
        'chapter_number',
        'title',
        'start_position',
        'end_position',
    ];

    protected function casts(): array
    {
        return [
            'chapter_number' => 'integer',
            'start_position' => 'integer',
            'end_position'   => 'integer',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
