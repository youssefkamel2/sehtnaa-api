<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Admin extends Model
{
    use LogsActivity;

    protected $fillable = ['user_id', 'role'];

    // Define which attributes should be logged
    protected static $logAttributes = ['user_id', 'role'];

    // Log only changed attributes
    protected static $logOnlyDirty = true;

    // Customize the log name
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'role'])
            ->logOnlyDirty()
            ->useLogName('admin');
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($admin) {
            if ($admin->role === 'super_admin') {
                return false; // Prevent deletion of super admin
            }
        });
    }
}