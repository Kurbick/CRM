<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'type', 'name', 'short_name', 'voen',
        'bank_name', 'iban', 'bank_code', 'bank_voen', 'swift',
        'legal_address', 'actual_address',
        'email', 'phone', 'website',
        'status', 'invoice_mode', 'comment',
    ];

    public function contacts()
    {
        return $this->hasMany(CompanyContact::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    protected static function booted()
    {
        static::deleting(function (Company $company) {
            $company->contacts()->delete();
        });
    }
    public function creditBalance(){
        return $this->hasOne(CreditBalance::class);
    }
    public function getOrCreateCreditBalance(): CreditBalance{
        return $this->creditBalance ?? $this->creditBalance()->create(['amount' => 0]);
    }

}