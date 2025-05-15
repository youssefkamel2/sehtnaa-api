<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Request extends Model
{
    protected $fillable = [
        'customer_id', 'phone', 'address', 'latitude', 'longitude',
        'additional_info', 'status', 'assigned_provider_id', 'scheduled_at',
        'started_at', 'completed_at', 'gender', 'current_search_radius', 
        'expansion_attempts', 'last_expansion_at', 'total_price', 'address', 'age'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_price' => 'decimal:2',
    ];

    /**
     * Prepare a date for array / JSON serialization.
     * This will format all dates to the application timezone (Africa/Cairo)
     * while keeping them in UTC in the database.
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->with('user');
    }
    
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'request_services')
                    ->withPivot('price')
                    ->withTimestamps();
    }

    public function assignedProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'assigned_provider_id')->with('user');
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(RequestCancellationLog::class);
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(RequestFeedback::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function isCancellable(): bool
    {
        $status = strtolower($this->getRawOriginal('status'));
        
        if ($status === 'pending') {
            return true;
        }
    
        if ($status === 'accepted' && $this->started_at) {
            return now()->diffInMinutes($this->started_at) <= 15;
        }
    
        return false;
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => str_replace('_', ' ', $value),
        );
    }

    public function requirements()
    {
        return $this->hasMany(RequestRequirement::class);
    }

    public function serviceRequirements()
    {
        return $this->hasManyThrough(
            ServiceRequirement::class,
            Service::class,
            'id',
            'service_id',
            'id',
            'id'
        )->via('services');
    }
}