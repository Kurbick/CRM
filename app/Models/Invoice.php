<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /**
     * Общая сумма всех подтверждённых платежей.
     *
     * Может быть больше суммы инвойса,
     * если клиент допустил переплату.
     */
    public function getPaidAmountAttribute(): float
    {
        return round(
            (float) $this->payments()
                ->where('status', 'confirmed')
                ->sum('amount'),
            2
        );
    }

    /**
     * Часть платежей, которая фактически
     * погашает сумму данного инвойса.
     *
     * Никогда не превышает total_amount.
     */
    public function getAppliedAmountAttribute(): float
    {
        return round(
            min(
                (float) $this->total_amount,
                (float) $this->paid_amount
            ),
            2
        );
    }

    /**
     * Переплата сверх суммы инвойса.
     */
    public function getOverpaymentAmountAttribute(): float
    {
        return round(
            max(
                0,
                (float) $this->paid_amount
                    - (float) $this->total_amount
            ),
            2
        );
    }

    /**
     * Остаток к оплате.
     *
     * Никогда не может быть отрицательным.
     */
    public function getRemainingAmountAttribute(): float
    {
        return round(
            max(
                0,
                (float) $this->total_amount
                    - (float) $this->paid_amount
            ),
            2
        );
    }

    /**
     * Просрочен ли инвойс.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (
            !$this->due_date
            || in_array(
                $this->status,
                ['paid', 'cancelled'],
                true
            )
        ) {
            return false;
        }

        return today()->gt(
            Carbon::parse($this->due_date)->startOfDay()
        );
    }

    protected static function booted(): void
    {
        static::deleting(function (Invoice $invoice): void {
            $invoice->lines()->delete();
        });
    }
}
