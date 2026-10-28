<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'code', 'name', 'swahili', 'short', 'is_paid_default', 'description'];

    protected function casts(): array
    {
        return [
            'is_paid_default' => 'boolean',
        ];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class, 'leave_type_id');
    }
}
