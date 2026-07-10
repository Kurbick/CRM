<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        $this->entries()->create([
            'type'        => 'top_up',
            'amount'      => $amount,
            'payment_id'  => $payment->id,
            'description' => "Переплата по платежу #{$payment->id}",
        ]);

        $this->increment('amount', $amount);
    }

    /**
     * Применить баланс к инвойсу.
     * Создаёт запись в журнале и уменьшает сумму.
     * Никогда не уходит в минус — проверяем перед списанием.
     */
    public function apply(float $amount, Invoice $invoice): float
    {
        // Сколько реально можем применить — не больше чем есть на балансе
        $toApply = min($amount, $this->amount);

        if ($toApply <= 0) {
            return 0;
        }

        $this->entries()->create([
            'type'        => 'applied',
            'amount'      => $toApply,
            'invoice_id'  => $invoice->id,
            'description' => "Применён к инвойсу #{$invoice->invoice_number}",
        ]);

        $this->decrement('amount', $toApply);

        return $toApply; // возвращаем сколько реально применили
    }
}