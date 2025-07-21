<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'notifiable_type',
        'notifiable_id',
        'type',
        'data',
        'read',
        'channels',
        'title',
        'message',
        'status',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'channels' => AsArrayObject::class,
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'data' => 'array',
        'read' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function notifiable()
    {
        return $this->morphTo();
    }
}
