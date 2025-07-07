<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaUpload extends Model
{
    /** @use HasFactory<\Database\Factories\MediaUploadFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'context',
        'type',
        'folder',
        'public_id',
        'url',
        'mediable_type',
        'mediable_id',
        'watermarked',
        'is_temporary',
    ];

    protected $casts = [
        'watermarked' => 'boolean',
        'is_temporary' => 'boolean',
    ];

    public function mediable()
    {
        return $this->morphTo();
    }
}
