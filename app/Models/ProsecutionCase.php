<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Court case or request docket (see `kind`). Table named `cases`; the class
 * avoids the PHP reserved word `case`.
 */
class ProsecutionCase extends Model
{
    protected $table = 'cases';

    protected $fillable = [
        'ref', 'kind', 'request_id', 'assigned_prosecutor_id', 'next_hearing_date',
        'status', 'owner_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'next_hearing_date' => 'date:Y-m-d',
        ];
    }

    public function prosecutor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_prosecutor_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(CaseHandover::class, 'case_id')->orderBy('id');
    }
}
