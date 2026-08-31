<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Services\ActiveOrganizationContext;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'issuer_organization_id',
        'contract_id',
        'invoice_number',
        'invoice_number_year',
        'invoice_number_sequence',
        'invoice_number_code',
        'issue_date',
        'due_date',
        'period_start',
        'period_end',
        'subtotal_amount',
        'vat_enabled',
        'vat_rate',
        'vat_amount',
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

    protected $casts = [
        'invoice_number_year' => 'integer',
        'invoice_number_sequence' => 'integer',
        'vat_enabled' => 'boolean',
        'vat_rate' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function issuerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'issuer_organization_id');
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
        return $this->paidMinorUnits() / 100;
    }

    private function paidMinorUnits(): int
    {
        if ($this->relationLoaded('payments')) {
            $paidMinor = $this->payments
                ->where('status', 'confirmed')
                ->sum(fn (Payment $payment): int => self::toMinorUnits(
                    $payment->getRawOriginal('amount') ?: $payment->amount
                ));
        } elseif (array_key_exists('confirmed_paid_amount', $this->attributes)) {
            $paidMinor = self::toMinorUnits($this->attributes['confirmed_paid_amount']);
        } else {
            $paidMinor = self::toMinorUnits($this->payments()
                ->where('status', 'confirmed')
                ->sum('amount'));
        }

        return $paidMinor;
    }

    /**
     * Часть платежей, которая фактически
     * погашает сумму данного инвойса.
     *
     * Никогда не превышает total_amount.
     */
    public function getAppliedAmountAttribute(): float
    {
        $paidMinor = $this->paidMinorUnits();

        return round(
            min(
                self::toMinorUnits($this->total_amount),
                $paidMinor
            ),
            2
        ) / 100;
    }

    /**
     * Переплата сверх суммы инвойса.
     */
    public function getOverpaymentAmountAttribute(): float
    {
        $paidMinor = $this->paidMinorUnits();

        return round(
            max(
                0,
                $paidMinor
                    - self::toMinorUnits($this->total_amount)
            ),
            2
        ) / 100;
    }

    /**
     * Остаток к оплате.
     *
     * Никогда не может быть отрицательным.
     */
    public function getRemainingAmountAttribute(): float
    {
        $paidMinor = $this->paidMinorUnits();

        return round(
            max(
                0,
                self::toMinorUnits($this->total_amount)
                    - $paidMinor
            ),
            2
        ) / 100;
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
        static::creating(function (Invoice $invoice): void {
            if ($invoice->getAttribute('subtotal_amount') === null && $invoice->getAttribute('total_amount') !== null) {
                $invoice->setAttribute('subtotal_amount', $invoice->getAttribute('total_amount'));
            }
            $invoice->vat_enabled ??= false;
            if ($invoice->getAttribute('vat_amount') === null) {
                $invoice->setAttribute('vat_amount', '0.00');
            }
            if ($invoice->issuer_organization_id === null) {
                $organization = app(ActiveOrganizationContext::class)->resolve();
                if ($organization !== null) {
                    $invoice->issuer_organization_id = $organization->getKey();
                }
            }
        });

        static::deleting(function (Invoice $invoice): void {
            $invoice->lines()->delete();
        });
    }

    private static function toMinorUnits(mixed $amount): int
    {
        $value = trim((string) ($amount ?? '0.00'));
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \LogicException("Invalid Invoice monetary value [{$value}].");
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }
}
