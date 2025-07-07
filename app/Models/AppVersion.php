<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AppVersion extends Model {
    use HasFactory;

    protected $table = 'app_versions';

    protected $fillable = [
        'app_id',
        'platform',
        'version',
        'force_update',
        'deprecated',
        'support_expires_at',
    ];

    protected $casts = [
        'force_update' => 'boolean',
        'deprecated' => 'boolean',
        'support_expires_at' => 'datetime',
    ];

    /**
     * Check if the app version requires an update.
     */
    public function requiresUpdate(): bool {
        return $this->force_update || $this->deprecated || ($this->support_expires_at && now()->greaterThanOrEqualTo($this->support_expires_at));
    }

    /**
     * Scope to get active supported versions.
     */
    public function scopeActive($query) {
        return $query->where('deprecated', false)
            ->where(function ($q) {
                $q->whereNull('support_expires_at')
                    ->orWhere('support_expires_at', '>', Carbon::now());
            });
    }
}

