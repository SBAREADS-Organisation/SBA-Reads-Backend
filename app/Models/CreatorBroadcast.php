<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An email a creator sent to their list. */
class CreatorBroadcast extends Model
{
    protected $fillable = ['store_id', 'subject', 'body', 'recipients'];
}
