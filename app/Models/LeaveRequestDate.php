<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestDate extends Model
{
    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['request_id', 'the_date', 'day_fraction'];

    protected function casts(): array
    {
        return [
            'the_date' => 'date:Y-m-d',
            'day_fraction' => 'float',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }
}
