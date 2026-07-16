<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'contract_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'period_start',
        'period_end',
        'total_amount',
        'status',
        'seller_name',
        'seller_voen',
        'seller_bank_name',
        'seller_iban',
        'seller_bank_code',
        'seller_bank_voen',
        'seller_swift',
        'payer_name',
        'payer_voen',
        'contract_reference',
        'comment',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Сколько уже оплачено
    public function getPaidAmountAttribute()
    {
        return $this->payments()
            ->where('status', 'confirmed')
            ->sum('amount');
    }

    // Остаток к оплате
    public function getRemainingAmountAttribute()
    {
        return $this->total_amount - $this->paid_amount;
    }

    // Просрочен ли инвойс
    public function getIsOverdueAttribute()
    {
        return !in_array($this->status, ['paid', 'cancelled'])
            && now()->toDateString() > $this->due_date;
    }

    protected static function booted()
    {
        static::deleting(function (Invoice $invoice) {
            $invoice->lines()->delete();
        });
    }
}
