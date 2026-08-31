<?php

namespace App\Support;

use App\Models\Invoice;
use App\Services\ActiveOrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

final class DashboardFinancials
{
    public function __construct(private readonly ActiveOrganizationContext $organizationContext) {}

    /** @var list<string> */
    public const ELIGIBLE_STATUSES = ['issued', 'partially_paid', 'paid'];

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
