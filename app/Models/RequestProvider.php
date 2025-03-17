<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestProvider extends Model
{
    protected $fillable = ['request_id', 'provider_id', 'status'];

    public function request()
    {
        return $this->belongsTo(Request::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
