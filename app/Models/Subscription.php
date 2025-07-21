<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    //
    protected $fillable = [
        'title', 'price', 'duration_in_days', 'perks', 'model', 'currencies',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'perks' => 'array',
            'currencies' => 'array',
            'price' => 'float',
        ];
    }
}
