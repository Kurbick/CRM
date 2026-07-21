<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'company_id',
        'payment_date',
        'amount',
        'payment_method',
        'status',
        'comment',
        'cancelled_at',
        'cancel_reason',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creditBalanceEntries(): HasMany
    {
        return $this->hasMany(CreditBalanceEntry::class);
    }

    /**
     * После подтверждения платежа:
     *
     * 1. пересчитываем статус инвойса;
     * 2. определяем общую переплату;
     * 3. зачисляем новую часть переплаты в Credit Balance.
     */
    protected static function booted(): void
    {
        static::saved(function (Payment $payment): void {
            /*
             * Обработка выполняется только:
             *
             * 1. при создании подтверждённого платежа;
             * 2. при переводе платежа из pending в confirmed.
             *
             * Повторное сохранение уже подтверждённого
             * платежа не должно повторно начислять баланс.
             */
            $becameConfirmed = $payment->status === 'confirmed'
                && (
                    $payment->wasRecentlyCreated
                    || (
                        $payment->wasChanged('status')
                        && $payment->getOriginal('status') === 'pending'
                    )
                );

            if (!$becameConfirmed) {
                return;
            }

            DB::transaction(function () use ($payment): void {
                /*
                 * Блокируем инвойс, чтобы одновременные
                 * платежи не нарушили расчёт суммы и статуса.
                 */
                $invoice = Invoice::query()
                    ->whereKey($payment->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Общая сумма всех действующих
                 * подтверждённых платежей.
                 */
                $totalPaid = round(
                    (float) $invoice->payments()
                        ->where('status', 'confirmed')
                        ->sum('amount'),
                    2
                );

                $invoiceTotal = round(
                    (float) $invoice->total_amount,
                    2
                );

                /*
                 * Определяем актуальный статус инвойса.
                 */
                if ($totalPaid >= $invoiceTotal) {
                    $invoiceStatus = 'paid';
                } elseif ($totalPaid > 0) {
                    $invoiceStatus = 'partially_paid';
                } else {
                    $invoiceStatus = 'issued';
                }

                /*
                 * Меняем статус только при необходимости.
                 */
                if ($invoice->status !== $invoiceStatus) {
                    $invoice->forceFill([
                        'status' => $invoiceStatus,
                    ])->saveQuietly();
                }

                /*
                 * Общая фактическая переплата
                 * по инвойсу на текущий момент.
                 */
                $totalOverpayment = round(
                    max(
                        0,
                        $totalPaid - $invoiceTotal
                    ),
                    2
                );

                if ($totalOverpayment <= 0) {
                    return;
                }

                $company = $invoice->company()
                    ->firstOrFail();

                $creditBalance = $company
                    ->getOrCreateCreditBalance();

                /*
                 * Сколько переплаты было начислено
                 * по всем платежам этого инвойса.
                 */
                $creditedAmount = round(
                    (float) $creditBalance->entries()
                        ->where('type', 'top_up')
                        ->whereHas(
                            'payment',
                            function ($query) use ($invoice): void {
                                $query->where(
                                    'invoice_id',
                                    $invoice->id
                                );
                            }
                        )
                        ->sum('amount'),
                    2
                );

                /*
                 * Сколько начисленной переплаты
                 * уже было отменено.
                 */
                $reversedAmount = round(
                    (float) $creditBalance->entries()
                        ->where('type', 'top_up_reversal')
                        ->whereHas(
                            'payment',
                            function ($query) use ($invoice): void {
                                $query->where(
                                    'invoice_id',
                                    $invoice->id
                                );
                            }
                        )
                        ->sum('amount'),
                    2
                );

                /*
                 * Фактически действующая сумма,
                 * уже зачисленная с этого инвойса.
                 */
                $netCreditedAmount = round(
                    max(
                        0,
                        $creditedAmount - $reversedAmount
                    ),
                    2
                );

                /*
                 * На Credit Balance отправляется только
                 * новая часть переплаты.
                 */
                $newOverpayment = round(
                    max(
                        0,
                        $totalOverpayment - $netCreditedAmount
                    ),
                    2
                );

                if ($newOverpayment <= 0) {
                    return;
                }

                $creditBalance->topUp(
                    $newOverpayment,
                    $payment
                );
            });
        });
    }
}
