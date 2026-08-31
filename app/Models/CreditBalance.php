<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreditBalance extends Model
{
    protected $fillable = ['company_id', 'organization_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
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
}
