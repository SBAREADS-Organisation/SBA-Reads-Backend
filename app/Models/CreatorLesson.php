<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorLesson extends Model
{
    public const KINDS = ['video', 'pdf', 'text', 'file'];

    protected $fillable = [
        'product_id', 'title', 'kind', 'position', 'file_key', 'file_name', 'file_size', 'body', 'is_preview',
    ];

    protected function casts(): array
    {
        return ['is_preview' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CreatorProduct::class, 'product_id');
    }
}
