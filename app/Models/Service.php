<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'description', 'provider_type', 'price', 'added_by'];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
    ];

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}