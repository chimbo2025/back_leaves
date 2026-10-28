<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'name', 'short', 'is_hq', 'is_pemba', 'is_coordination', 'parent_zone_id'];

    protected function casts(): array
    {
        return [
            'is_hq' => 'boolean',
            'is_pemba' => 'boolean',
            'is_coordination' => 'boolean',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
