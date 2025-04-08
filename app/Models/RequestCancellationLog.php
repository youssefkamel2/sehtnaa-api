<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestCancellationLog extends Model
{
    protected $fillable = [
        'request_id', 'cancelled_by', 'reason', 'is_after_acceptance', 'cancelled_at'
    ];

    protected $casts = [
        'is_after_acceptance' => 'boolean',
        'cancelled_at' => 'datetime'
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }
}