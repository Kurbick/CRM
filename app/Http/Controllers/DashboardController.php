<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\ServiceType;
use App\Models\Subscription;
use App\Support\DashboardFinancials;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Общая статистика по всей системе.
     * Один запрос к каждой таблице — быстро и эффективно.
     */
    public function overview(DashboardFinancials $financials): JsonResponse
    {
        $overview = $financials->overview(now()->toDateString());

        return response()->json([
            ...$overview,
            'active_companies' => Company::where('status', 'active')->count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
        ]);
    }

    /**
     * Список всех компаний с долгами и статистикой.
     * withCount и withSum делают всё в одном SQL запросе.
     */
    public function companies(DashboardFinancials $financials): JsonResponse
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
                'invoices' => function ($q) use ($financials) {
                    $financials->constrainOutstanding($q)
                        ->orderBy('due_date', 'asc')
                        ->orderBy('id')
                        ->limit(1);
                    $financials->addRemainingAmount($q);
                },
            ])
            ->get();
        $companyFinancials = $financials->byCompany($companies->pluck('id'), now()->toDateString());

        $companies = $companies->map(function ($company) use ($companyFinancials) {
            $summary = $companyFinancials->get($company->id);

            return [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status,
                'invoice_mode' => $company->invoice_mode,
                'total_debt' => $summary?->total_debt ?? '0.00',
                'has_overdue' => (int) ($summary?->overdue_count ?? 0) > 0,
                'active_contracts_count' => $company->active_contracts_count,
                'last_payment_date' => $company->payments->first()?->payment_date,
                'next_due_date' => $company->invoices->first()?->due_date,
                'next_due_amount' => $company->invoices->first()?->dashboard_remaining_amount,
            ];
        });

        return response()->json($companies);
    }

    /**
     * Детальная статистика по одной компании.
     */
    public function company(Company $company, DashboardFinancials $financials): JsonResponse
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
            ->orderBy('issue_date', 'desc')
            ->tap(fn ($query) => $financials->addEffectiveAmounts($query, now()->toDateString()))
            ->get()
            ->map(function (Invoice $invoice): array {
                return [
                    'id' => (int) $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'issue_date' => $this->dateValue($invoice->issue_date),
                    'due_date' => $this->dateValue($invoice->due_date),
                    'total_amount' => $invoice->total_amount,
                    'paid_amount' => $invoice->getAttribute('effective_paid_amount'),
                    'remaining' => $invoice->getAttribute('remaining_amount'),
                    'status' => $invoice->status,
                    'is_overdue' => (bool) $invoice->getAttribute('dashboard_is_overdue'),
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

        $totalDebt = $financials->sumDecimals($invoices->pluck('remaining'));

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
