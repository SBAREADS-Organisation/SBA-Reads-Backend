<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookAudit extends Model
{
    /** @use HasFactory<\Database\Factories\BookAuditFactory> */
    use HasFactory;

    protected $fillable = ['book_id', 'admin_id', 'action', 'note', 'acted_at'];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
