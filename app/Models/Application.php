<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    public const TYPES = ['listing', 'publishing', 'isbn'];

    public const STATUSES = ['new', 'in_review', 'awaiting_payment', 'completed', 'rejected'];

    protected $fillable = [
        'type',
        'book_title',
        'author_name',
        'email',
        'details',
        'files',
        'status',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'files' => 'array',
        ];
    }
}
