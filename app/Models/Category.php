<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    // Fillable
    protected $fillable = [
        'name',
        'slug',
        'description',
        'image',
        'order',
        'is_active',
        'created_by',
        'parent_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'parent_id' => 'integer',
            'image' => 'json',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function books()
    {
        return $this->belongsToMany(Book::class, 'book_categories');
    }
}
