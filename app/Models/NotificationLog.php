<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'campaign_id',
        'user_id',
        'title',
        'body',
        'data',
        'is_sent',
        'error_message'
    ];

    protected $casts = [
        'data' => 'array',
        'is_sent' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}