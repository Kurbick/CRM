<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreditBalance extends Model
{
    protected $fillable = ['company_id', 'amount'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function entries()
    {
        return $this->hasMany(CreditBalanceEntry::class);
    }

    /**
     * Пополнить баланс.
     * Создаёт запись в журнале и увеличивает сумму.
     */
    public function topUp(float $amount, Payment $payment): void
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($amount, $payment) {
            /*
         * Блокируем баланс компании, чтобы два запроса
         * не могли одновременно начислить одну переплату.
         */
            $balance = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
         * Один платёж может пополнить Credit Balance
         * только один раз.
         */
            $alreadyRecorded = $balance->entries()
                ->where('type', 'top_up')
                ->where('payment_id', $payment->id)
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            $balance->entries()->create([
                'type' => 'top_up',
                'amount' => $amount,
                'payment_id' => $payment->id,
                'description' => "Переплата по платежу #{$payment->id}",
            ]);

            $balance->increment('amount', $amount);

            /*
         * Обновляем текущий экземпляр модели,
         * чтобы в памяти не оставалась старая сумма.
         */
            $this->setAttribute(
                'amount',
                (float) $balance->fresh()->amount
            );
        });
    }

    /**
     * Применить баланс к инвойсу.
     * Создаёт запись в журнале и уменьшает сумму.
     * Никогда не уходит в минус — проверяем перед списанием.
     */
    public function apply(float $amount, Invoice $invoice): float
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($amount, $invoice) {
            /*
         * Блокируем баланс на время списания.
         */
            $balance = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
         * На один инвойс Credit Balance может быть
         * применён только один раз.
         */
            $alreadyApplied = $balance->entries()
                ->where('type', 'applied')
                ->where('invoice_id', $invoice->id)
                ->exists();

            if ($alreadyApplied) {
                return 0;
            }

            /*
         * Не списываем больше суммы инвойса
         * и больше доступного баланса.
         */
            $availableAmount = round(
                (float) $balance->amount,
                2
            );

            $toApply = min(
                $amount,
                $availableAmount
            );

            if ($toApply <= 0) {
                return 0;
            }

            $balance->entries()->create([
                'type' => 'applied',
                'amount' => $toApply,
                'invoice_id' => $invoice->id,
                'description' =>
                "Применён к инвойсу #{$invoice->invoice_number}",
            ]);

            $balance->decrement(
                'amount',
                $toApply
            );

            $this->setAttribute(
                'amount',
                (float) $balance->fresh()->amount
            );

            return $toApply;
        });
    }
}
