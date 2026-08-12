<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    public const SINGLETON_KEY = 'own';

    protected $fillable = [
        'name',
        'voen',
        'bank_name',
        'iban',
        'bank_code',
        'bank_voen',
        'swift',
    ];

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('singleton_key', self::SINGLETON_KEY);
    }

    public static function current(): ?self
    {
        return self::query()->current()->first();
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->singleton_key ??= self::SINGLETON_KEY;
        });
    }
}
