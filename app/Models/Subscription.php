<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'contract_id', 'service_type_id', 'start_date',
        'next_billing_date', 'billing_period', 'amount',
        'payment_terms', 'status', 'comment',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function invoiceLines()
    {
        return $this->hasMany(InvoiceLine::class);
    }
}