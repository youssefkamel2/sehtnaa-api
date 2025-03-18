<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use DateTimeInterface;


class User extends Authenticatable implements JWTSubject
{
    use SoftDeletes, Notifiable, LogsActivity;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'password', 'user_type', 'status', 'address', 'latitude', 'longitude'
    ];

    protected $hidden = ['password'];

    // Define which attributes should be logged
    protected static $logAttributes = ['first_name', 'last_name', 'email', 'phone', 'user_type', 'status'];

    // Log only changed attributes
    protected static $logOnlyDirty = true;

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s'); // Format the date in the desired time zone
    }

    // Customize the log name
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'email', 'phone', 'user_type', 'status'])
            ->logOnlyDirty()
            ->useLogName('user');
    }

    // JWT methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // Relationships
    public function customer()
    {
        return $this->hasOne(Customer::class);
    }

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function provider()
    {
        return $this->hasOne(Provider::class);
    }
}