<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderDocument extends Model
{
    use HasFactory;

    protected $fillable = ['provider_id', 'required_document_id', 'document_path', 'status', 'rejection_reason'];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function requiredDocument()
    {
        return $this->belongsTo(RequiredDocument::class);
    }
}