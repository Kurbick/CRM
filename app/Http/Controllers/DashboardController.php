<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\Subscription;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;

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
            'total_invoiced' => $totalInvoiced,
            'total_paid' => $totalPaid,
            'total_debt' => $totalDebt,
            'overdue_count' => $overdueCount,
            'overdue_amount' => $overdueAmount,
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
                    'id' => $company->id,
                    'name' => $company->name,
                    'status' => $company->status,
                    'invoice_mode' => $company->invoice_mode,
                    'total_debt' => $debt,
                    'has_overdue' => $hasOverdue,
                    'active_contracts_count' => $company->active_contracts_count,
                    'last_payment_date' => $company->payments->first()?->payment_date,
                    'next_due_date' => $company->invoices->first()?->due_date,
                    'next_due_amount' => $company->invoices->first()?->total_amount,
                ];
            });

        return response()->json($companies);
    }

    /**
     * Детальная статистика по одной компании.
     */
    public function company(Company $company): JsonResponse
    {
        $invoices = Invoice::query()
            ->where('company_id', $company->id)
            ->select([
                'id',
                'company_id',
                'invoice_number',
                'issue_date',
                'due_date',
                'total_amount',
                'status',
            ])
            ->withSum([
                'payments as confirmed_paid_amount' => fn ($query) => $query
                    ->where('status', 'confirmed'),
            ], 'amount')
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function (Invoice $invoice): array {
                $paidAmount = (float) ($invoice->getAttribute('confirmed_paid_amount') ?? 0);

                return [
                    'id' => (int) $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'issue_date' => $this->dateValue($invoice->issue_date),
                    'due_date' => $this->dateValue($invoice->due_date),
                    'total_amount' => $invoice->total_amount,
                    'paid_amount' => $paidAmount,
                    'remaining' => (float) $invoice->total_amount - $paidAmount,
                    'status' => $invoice->status,
                    'is_overdue' => $invoice->status !== 'paid'
                                        && $invoice->status !== 'cancelled'
                                        && $this->dateValue($invoice->due_date) < now()->toDateString(),
                ];
            });

        $subscriptions = Subscription::query()
            ->whereHas('contract', function ($query) use ($company): void {
                $query->where('company_id', $company->id);
            })
            ->where('status', 'active')
            ->select([
                'id',
                'service_type_id',
                'title',
                'status',
                'amount',
                'billing_period',
                'next_billing_date',
            ])
            ->with('serviceType:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Subscription $subscription): array => $this->subscriptionProjection($subscription));

        $totalDebt = $invoices->sum('remaining');

        return response()->json([
            'company' => $this->companyProjection($company),
            'total_debt' => $totalDebt,
            'invoices' => $invoices,
            'subscriptions' => $subscriptions,
        ]);
    }

    /** @return array{id: int, name: string, status: string, invoice_mode: string} */
    private function companyProjection(Company $company): array
    {
        return [
            'id' => (int) $company->id,
            'name' => $company->name,
            'status' => $company->status,
            'invoice_mode' => $company->invoice_mode,
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionProjection(Subscription $subscription): array
    {
        return [
            'id' => (int) $subscription->id,
            'title' => $subscription->title,
            'status' => $subscription->status,
            'amount' => $subscription->getRawOriginal('amount'),
            'billing_period' => $subscription->billing_period,
            'next_billing_date' => $this->dateValue($subscription->next_billing_date),
            'service_type' => $this->serviceTypeProjection($subscription->serviceType),
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function serviceTypeProjection(?ServiceType $serviceType): ?array
    {
        if ($serviceType === null) {
            return null;
        }

        return [
            'id' => (int) $serviceType->id,
            'name' => $serviceType->name,
        ];
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }
}
