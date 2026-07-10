<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditBalanceEntry extends Model
{
    protected $fillable = [
        'credit_balance_id', 'type', 'amount',
        'payment_id', 'invoice_id', 'description',
    ];

    public function creditBalance()
    {
        return $this->belongsTo(CreditBalance::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}