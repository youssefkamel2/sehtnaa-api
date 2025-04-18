<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestRequirement extends Model
{
    protected $fillable = [
        'request_id', 
        'service_requirement_id',
        'value',
        'file_path'
    ];

    public function request()
    {
        return $this->belongsTo(Request::class);
    }

    public function serviceRequirement()
    {
        return $this->belongsTo(ServiceRequirement::class);
    }
}