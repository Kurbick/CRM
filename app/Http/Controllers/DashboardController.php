<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Общая статистика по всей системе.
     * Один запрос к каждой таблице — быстро и эффективно.
     */
    public function overview(): JsonResponse
    {
        // Общий долг = сумма всех неоплаченных инвойсов
        // минус сумма всех подтверждённых платежей
        $totalInvoiced = Invoice::whereNotIn('status', ['cancelled'])
            ->sum('total_amount');

        $totalPaid = Payment::where('status', 'confirmed')
        ->where('comment', 'not like', '%Credit Balance%')
        ->sum('amount');

        $totalDebt = $totalInvoiced - $totalPaid;

        // Просроченные инвойсы — due_date прошёл, не оплачены
        $overdueCount = Invoice::whereNotIn('status', ['paid', 'cancelled'])
            ->where('due_date', '<', now()->toDateString())
            ->count();

        $overdueAmount = Invoice::whereNotIn('status', ['paid', 'cancelled'])
            ->where('due_date', '<', now()->toDateString())
            ->sum('total_amount');

        return response()->json([
            'total_invoiced'   => $totalInvoiced,
            'total_paid'       => $totalPaid,
            'total_debt'       => $totalDebt,
            'overdue_count'    => $overdueCount,
            'overdue_amount'   => $overdueAmount,
            'active_companies' => Company::where('status', 'active')->count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
        ]);
    }

    /**
     * Список всех компаний с долгами и статистикой.
     * withCount и withSum делают всё в одном SQL запросе.
     */
    public function companies(): JsonResponse
    {
        $companies = Company::where('status', '!=', 'archived')
            ->withCount([
                // Считаем количество активных контрактов
                'contracts as active_contracts_count' => function ($q) {
                    $q->where('status', 'active');
                },
                // Считаем количество активных подписок через контракты
                'contracts as active_subscriptions_count' => function ($q) {
                    $q->whereHas('subscriptions', function ($sq) {
                        $sq->where('status', 'active');
                    });
                },
            ])
            ->with([
                // Последний платёж по каждой компании
                'payments' => function ($q) {
                    $q->where('status', 'confirmed')
                      ->orderBy('payment_date', 'desc')
                      ->limit(1);
                },
                // Ближайший инвойс к оплате
                'invoices' => function ($q) {
                    $q->whereNotIn('status', ['paid', 'cancelled'])
                      ->orderBy('due_date', 'asc')
                      ->limit(1);
                },
            ])
            ->get()
            ->map(function ($company) {
                // Считаем долг по каждой компании
                $invoiced = Invoice::where('company_id', $company->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('total_amount');

                $paid = Payment::where('company_id', $company->id)
                    ->where('status', 'confirmed')
                    ->where('comment', 'not like', '%Credit Balance%')
                    ->sum('amount');

                $debt = max(0, $invoiced - $paid);

                // Есть ли просроченные инвойсы
                $hasOverdue = Invoice::where('company_id', $company->id)
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->where('due_date', '<', now()->toDateString())
                    ->exists();

                return [
                    'id'                       => $company->id,
                    'name'                     => $company->name,
                    'status'                   => $company->status,
                    'invoice_mode'             => $company->invoice_mode,
                    'total_debt'               => $debt,
                    'has_overdue'              => $hasOverdue,
                    'active_contracts_count'   => $company->active_contracts_count,
                    'last_payment_date'        => $company->payments->first()?->payment_date,
                    'next_due_date'            => $company->invoices->first()?->due_date,
                    'next_due_amount'          => $company->invoices->first()?->total_amount,
                ];
            });

        return response()->json($companies);
    }

    /**
     * Детальная статистика по одной компании.
     */
    public function company(Company $company): JsonResponse
    {
        // Все инвойсы компании с платежами
        $invoices = Invoice::where('company_id', $company->id)
            ->with('payments')
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function ($invoice) {
                $paidAmount = $invoice->payments
                    ->where('status', 'confirmed')
                    ->sum('amount');

                return [
                    'id'             => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'issue_date'     => $invoice->issue_date,
                    'due_date'       => $invoice->due_date,
                    'total_amount'   => $invoice->total_amount,
                    'paid_amount'    => $paidAmount,
                    'remaining'      => $invoice->total_amount - $paidAmount,
                    'status'         => $invoice->status,
                    'is_overdue'     => $invoice->status !== 'paid'
                                        && $invoice->status !== 'cancelled'
                                        && $invoice->due_date < now()->toDateString(),
                ];
            });

        // Активные подписки
        $subscriptions = Subscription::whereHas('contract', function ($q) use ($company) {
            $q->where('company_id', $company->id);
        })
        ->where('status', 'active')
        ->with('serviceType')
        ->get();

        // Итоговый долг
        $totalDebt = $invoices->sum('remaining');

        return response()->json([
            'company'       => $company,
            'total_debt'    => $totalDebt,
            'invoices'      => $invoices,
            'subscriptions' => $subscriptions,
        ]);
    }
}