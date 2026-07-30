<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'contract_id', 'service_type_id', 'title', 'start_date',
        'billing_period', 'custom_interval_value', 'custom_interval_unit', 'amount',
        'payment_terms', 'status', 'comment',
    ];

    protected $casts = [
        'start_date' => 'date',
        'next_billing_date' => 'date',
        'custom_interval_value' => 'integer',
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
