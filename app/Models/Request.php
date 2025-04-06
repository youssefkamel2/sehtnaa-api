<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Request extends Model
{
    protected $fillable = [
        'customer_id', 'service_id', 'phone', 'address', 'latitude', 'longitude',
        'additional_info', 'status', 'assigned_provider_id', 'scheduled_at',
        'completed_at', 'cancelled_at', 'cancellation_reason'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->with('user');
    }

    public function service()
    {
        return $this->belongsTo(Service::class)->with('category');
    }

    public function assignedProvider()
    {
        return $this->belongsTo(Provider::class, 'assigned_provider_id')->with('user');
    }

    // Accessor for status
    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => ucfirst($value),
        );
    }
}