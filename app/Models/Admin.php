<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Admin extends Model
{
    use LogsActivity;

    protected $fillable = ['user_id', 'role'];

    protected static $logAttributes = ['user_id', 'role'];

    protected static $logOnlyDirty = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'role'])
            ->logOnlyDirty()
            ->useLogName('admin');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($admin) {
            if ($admin->role === 'super_admin') {
                return false;
            }
        });
    }
}