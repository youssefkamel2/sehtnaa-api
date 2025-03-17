<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Request extends Model
{
    protected $fillable = [
        'customer_id', 'service_id', 'phone', 'address', 'additional_info', 'status', 'assigned_provider_id'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function assignedProvider()
    {
        return $this->belongsTo(Provider::class, 'assigned_provider_id');
    }
}
