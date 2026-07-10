<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Payment extends Model
{
    protected $fillable = [
        'invoice_id', 'company_id', 'payment_date',
        'amount', 'payment_method', 'status', 'comment',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // После подтверждения платежа — обновляем статус инвойса
    protected static function booted()
    {
    static::saved(function (Payment $payment) {
        \Log::info('Payment saved', ['status' => $payment->status, 'amount' => $payment->amount]);
        if ($payment->status === 'confirmed') {
            $invoice = $payment->invoice;
            $company = $invoice->company;

            // Сколько уже оплачено по инвойсу (включая этот платёж)
            $totalPaid = $invoice->payments()
                ->where('status', 'confirmed')
                ->sum('amount');

            if ($totalPaid >= $invoice->total_amount) {
                // Платёж покрывает инвойс полностью или с излишком
                $invoice->update(['status' => 'paid']);

                // Считаем переплату
                $overpaid = $totalPaid - $invoice->total_amount;

                if ($overpaid > 0) {
                    // Зачисляем переплату на баланс компании
                    $creditBalance = $company->getOrCreateCreditBalance();
                    $creditBalance->topUp($overpaid, $payment);
                }
            } elseif ($totalPaid > 0) {
                $invoice->update(['status' => 'partially_paid']);
            }
        }
    });
    }
}