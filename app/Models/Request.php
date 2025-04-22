<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Request extends Model
{
    protected $fillable = [
        'customer_id', 'service_id', 'phone', 'address', 'latitude', 'longitude',
        'additional_info', 'status', 'assigned_provider_id', 'scheduled_at',
        'started_at', 'completed_at', 'gender', 'current_search_radius', 'expansion_attempts', 'last_expansion_at'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->with('user');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->with('category');
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
        // Get raw status value bypassing the accessor
        $status = strtolower($this->getRawOriginal('status'));
        
        // Request can be cancelled if it's pending or within 15 minutes of acceptance
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
            'id', // Foreign key on services table
            'service_id', // Foreign key on service_requirements table
            'service_id', // Local key on requests table
            'id' // Local key on services table
        );
    }


}