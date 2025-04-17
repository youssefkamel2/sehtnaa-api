<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $casts = [
        'data' => 'array',
        'response_data' => 'array'
    ];

    protected $fillable = [
        'campaign_id',
        'user_id',
        'title',
        'body',
        'data',
        'is_sent',
        'error_message',
        'response_data'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}