<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    public const SINGLETON_KEY = 'own';

    protected $fillable = [
        'name',
        'legal_name',
        'voen',
        'bank_name',
        'iban',
        'bank_correspondent_account',
        'bank_code',
        'bank_voen',
        'swift',
        'invoice_number_code',
        'is_active',
        'is_vat_payer',
        'vat_rate',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_vat_payer' => 'boolean',
        'vat_rate' => 'decimal:2',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'issuer_organization_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'issuer_organization_id');
    }

    public function invoiceNumberCounters(): HasMany
    {
        return $this->hasMany(InvoiceNumberCounter::class);
    }

    public function creditBalances(): HasMany
    {
        return $this->hasMany(CreditBalance::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->singleton_key ??= self::SINGLETON_KEY;
            $organization->is_active ??= true;
        });
    }
}
