<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id', 'event_type', 'email_enabled', 'database_enabled', 'language', 'timing_config_json',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'database_enabled' => 'boolean',
            'timing_config_json' => 'array',
        ];
    }
}
