<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $casts = [
        'data' => 'array',
        'response' => 'array',
        'attempt_logs' => 'array',
        'is_sent' => 'boolean',
        'sent_at' => 'datetime'
    ];

    protected $fillable = [
        'campaign_id',
        'user_id',
        'user_type',
        'title',
        'body',
        'data',
        'response',
        'attempt_logs',
        'device_token',
        'attempts_count',
        'is_sent',
        'error_message'
    ];

    protected $dates = [
        'sent_at',
        'created_at',
        'updated_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForCampaign($query, $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function scopeSent($query)
    {
        return $query->where('is_sent', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('is_sent', false)
                    ->where('attempts_count', '>=', config('notification.max_attempts', 3));
    }

    public function scopePending($query)
    {
        return $query->where('is_sent', false)
                    ->where('attempts_count', '<', config('notification.max_attempts', 3));
    }
}