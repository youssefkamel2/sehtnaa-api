<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'icon',
        'is_active',
        'order',
        'added_by'
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'is_active' => 'boolean'
    ];

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('name');
    }

    public function getTranslation(string $field, string $locale, bool $useFallbackLocale = true)
    {
        $translations = $this->getAttribute($field);
        $locale = $this->normalizeLocale($locale, $field, $useFallbackLocale);

        return $translations[$locale] ?? $translations[config('app.fallback_locale')] ?? null;
    }

    protected function normalizeLocale(string $locale, string $field, bool $useFallbackLocale): string
    {
        $locales = config('laravellocalization.supportedLocales', []);
        
        if (isset($locales[$locale])) {
            return $locale;
        }

        if (!$useFallbackLocale) {
            return $locale;
        }

        return config('app.fallback_locale', 'en');
    }
}