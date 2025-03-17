<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Customer extends Model
{
    use LogsActivity;

    protected $fillable = ['user_id', 'additional_info'];

    // Define which attributes should be logged
    protected static $logAttributes = ['user_id', 'additional_info'];

    // Log only changed attributes
    protected static $logOnlyDirty = true;

    // Customize the log name
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'additional_info'])
            ->logOnlyDirty()
            ->useLogName('customer');
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}