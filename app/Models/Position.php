<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'title', 'department_id', 'zone_id', 'tier', 'is_final_authority'];

    protected function casts(): array
    {
        return [
            'is_final_authority' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function defaultRoles(): HasMany
    {
        return $this->hasMany(PositionDefaultRole::class, 'position_id');
    }
}
