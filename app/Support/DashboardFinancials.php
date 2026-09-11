<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ActiveOrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

final class DashboardFinancials
{
    public function __construct(private readonly ActiveOrganizationContext $organizationContext) {}

    /** @var list<string> */
    public const ELIGIBLE_STATUSES = ['issued', 'partially_paid', 'paid'];

    public const PERIOD_THIS_MONTH = 'this_month';

    /** @var list<string> */
    public const PERIOD_KEYS = [self::PERIOD_THIS_MONTH, '3m', '6m', '1y', 'all', 'custom'];

    private const CONFIRMED_SETTLEMENT = "(
        SELECT COALESCE(SUM(dashboard_payments.amount), 0)
        FROM payments AS dashboard_payments
        WHERE dashboard_payments.invoice_id = invoices.id
          AND dashboard_payments.status = 'confirmed'
    )";

    private const EFFECTIVE_PAID = '(CASE
        WHEN '.self::CONFIRMED_SETTLEMENT.' >= invoices.total_amount THEN invoices.total_amount
        ELSE '.self::CONFIRMED_SETTLEMENT.'
    END)';

    private const REMAINING = '(CASE
        WHEN '.self::CONFIRMED_SETTLEMENT.' >= invoices.total_amount THEN 0
        ELSE invoices.total_amount - '.self::CONFIRMED_SETTLEMENT.'
    END)';

    /** @return array{total_invoiced: mixed, total_paid: mixed, total_debt: mixed, overdue_count: int, overdue_amount: mixed} */
    public function overview(string $today): array
    {
        $row = Invoice::query()
            ->tap(fn ($query) => $this->scopeInvoices($query))
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->selectRaw('COALESCE(SUM(invoices.total_amount), 0) AS total_invoiced')
            ->selectRaw('COALESCE(SUM('.self::EFFECTIVE_PAID.'), 0) AS total_paid')
            ->selectRaw('COALESCE(SUM('.self::REMAINING.'), 0) AS total_debt')
            ->selectRaw(
                'SUM(CASE WHEN invoices.due_date < ? AND '.self::REMAINING.' > 0 THEN 1 ELSE 0 END) AS overdue_count',
                [$today]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN invoices.due_date < ? THEN '.self::REMAINING.' ELSE 0 END), 0) AS overdue_amount',
                [$today]
            )
            ->firstOrFail();

        return [
            'total_invoiced' => $row->total_invoiced,
            'total_paid' => $row->total_paid,
            'total_debt' => $row->total_debt,
            'overdue_count' => (int) $row->overdue_count,
            'overdue_amount' => $row->overdue_amount,
        ];
    }

    /**
     * Resolve a dashboard period into inclusive calendar dates.
     *
     * @return array{key: string, from: ?string, to: ?string}
     */
    public function periodRange(
        string $key,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?CarbonImmutable $today = null,
    ): array {
        $today ??= CarbonImmutable::today();

        return match ($key) {
            self::PERIOD_THIS_MONTH => [
                'key' => self::PERIOD_THIS_MONTH,
                'from' => $today->startOfMonth()->toDateString(),
                'to' => $today->endOfMonth()->toDateString(),
            ],
            '3m' => $this->rollingMonthRange($key, $today, 3),
            '6m' => $this->rollingMonthRange($key, $today, 6),
            '1y' => $this->rollingMonthRange($key, $today, 12),
            'all' => [
                'key' => 'all',
                'from' => null,
                'to' => null,
            ],
            'custom' => [
                'key' => 'custom',
                'from' => CarbonImmutable::parse($dateFrom)->toDateString(),
                'to' => CarbonImmutable::parse($dateTo)->toDateString(),
            ],
        };
    }

    /**
     * Period-based totals intentionally remain separate from current-state debt metrics.
     *
     * @return array{total_invoiced: mixed, total_paid: mixed}
     */
    public function periodOverview(?string $dateFrom, ?string $dateTo, ?int $companyId = null): array
    {
        $invoiced = Invoice::query()
            ->tap(fn ($query) => $this->scopeInvoices($query))
            ->whereIn('invoices.status', self::ELIGIBLE_STATUSES)
            ->when($companyId !== null, fn ($query) => $query->where('invoices.company_id', $companyId))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('invoices.issue_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('invoices.issue_date', '<=', $dateTo))
            ->sum('invoices.total_amount');

        $paid = Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->tap(fn ($query) => $this->scopeInvoices($query))
            ->whereIn('invoices.status', self::ELIGIBLE_STATUSES)
            ->when($companyId !== null, fn ($query) => $query->where('invoices.company_id', $companyId))
            ->where('payments.status', 'confirmed')
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('payments.payment_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('payments.payment_date', '<=', $dateTo))
            ->sum('payments.amount');

        return [
            'total_invoiced' => $invoiced,
            'total_paid' => $paid,
        ];
    }

    /** @return array{key: string, from: string, to: string} */
    private function rollingMonthRange(string $key, CarbonImmutable $today, int $months): array
    {
        return [
            'key' => $key,
            'from' => $today->startOfMonth()->subMonths($months - 1)->toDateString(),
            'to' => $today->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return Collection<int, object>
     */
    public function byCompany(Collection $companyIds, string $today): Collection
    {
        if ($companyIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->whereIn('company_id', $companyIds)
            ->tap(fn ($query) => $this->scopeInvoices($query))
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->groupBy('company_id')
            ->select('company_id')
            ->selectRaw('COALESCE(SUM('.self::REMAINING.'), 0) AS total_debt')
            ->selectRaw(
                'SUM(CASE WHEN invoices.due_date < ? AND '.self::REMAINING.' > 0 THEN 1 ELSE 0 END) AS overdue_count',
                [$today]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN invoices.due_date < ? AND '.self::REMAINING.' > 0 THEN '.self::REMAINING.' ELSE 0 END), 0) AS overdue_amount',
                [$today]
            )
            ->get()
            ->keyBy('company_id');
    }

    public function addEffectiveAmounts(Builder $query, string $today): Builder
    {
        return $query
            ->tap(fn ($query) => $this->scopeInvoices($query))
            ->whereIn('invoices.status', self::ELIGIBLE_STATUSES)
            ->selectRaw(self::EFFECTIVE_PAID.' AS effective_paid_amount')
            ->selectRaw(self::REMAINING.' AS remaining_amount')
            ->selectRaw(
                'CASE WHEN invoices.due_date < ? AND '.self::REMAINING.' > 0 THEN 1 ELSE 0 END AS dashboard_is_overdue',
                [$today]
            );
    }

    public function constrainOutstanding(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->tap(fn ($query) => $this->scopeInvoices($query))
            ->whereIn('invoices.status', self::ELIGIBLE_STATUSES)
            ->whereRaw(self::REMAINING.' > 0');
    }

    public function constrainOverdue(Builder|Relation $query, string $today): Builder|Relation
    {
        return $this->constrainOutstanding($query)
            ->where('invoices.due_date', '<', $today);
    }

    public function addRemainingAmount(Builder|Relation $query): Builder|Relation
    {
        return $query->selectRaw(self::REMAINING.' AS dashboard_remaining_amount');
    }

    public function scopeInvoices(Builder|Relation $query): Builder|Relation
    {
        return $this->organizationContext->scopeFor(
            $query,
            $this->organizationContext->resolve(),
            'invoices.issuer_organization_id',
        );
    }

    /** @param  iterable<mixed>  $amounts */
    public function sumDecimals(iterable $amounts): string
    {
        $minor = 0;

        foreach ($amounts as $amount) {
            [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
            $minor += ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
