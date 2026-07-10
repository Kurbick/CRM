<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'contract_id', 'service_type_id', 'order_date',
        'deadline', 'price', 'payment_terms', 'status', 'comment',
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