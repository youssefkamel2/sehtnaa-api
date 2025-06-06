<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Provider extends Model
{
    use LogsActivity;

    protected $fillable = ['user_id', 'provider_type', 'nid', 'is_available'];

    // Define which attributes should be logged
    protected static $logAttributes = ['user_id', 'provider_type'];

    // Log only changed attributes
    protected static $logOnlyDirty = true;

    // Customize the log name
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'provider_type'])
            ->logOnlyDirty()
            ->useLogName('provider');
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(ProviderDocument::class);
    }

    // requests
    public function requests()
    {
        return $this->hasMany(Request::class);
    }
    public function requestProviders()
    {
        return $this->hasMany(RequestProvider::class);
    }

    public function assignedRequests()
{
    return $this->hasMany(Request::class, 'assigned_provider_id');
}
}