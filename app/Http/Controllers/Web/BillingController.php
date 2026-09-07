<?php

namespace App\Http\Controllers\Web;

use App\Actions\Invoices\CreateBillingRunDrafts;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\ActiveOrganizationContext;
use App\Services\BillingPeriodPreview;
use App\Services\InvoicePaymentAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class BillingController extends Controller
{
    private const RESULT_SESSION_KEY = 'billing_run_result';

    public function index(Request $request, BillingPeriodPreview $preview): View
    {
        return $this->render($request, $preview);
    }

    public function preview(Request $request, BillingPeriodPreview $preview): View
    {
        return $this->render($request, $preview);
    }

    public function result(
        Request $request,
        ActiveOrganizationContext $organizationContext,
        InvoicePaymentAvailabilityService $money,
    ): View {
        Gate::authorize('create', Invoice::class);

        $context = $request->session()->get(self::RESULT_SESSION_KEY);
        abort_unless(
            is_array($context)
                && (int) ($context['user_id'] ?? 0) === (int) $request->user()->getKey(),
            404,
        );

        $period = $this->periodFromContext($context);
        $invoiceIds = collect($context['created_invoice_ids'] ?? [])
            ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $organization = $organizationContext->resolve($request);
        $invoices = Invoice::query()
            ->with(['company', 'contract', 'lines'])
            ->tap(fn ($query) => $organizationContext->scopeFor($query, $organization))
            ->whereIn('id', $invoiceIds)
            ->get()
            ->sortBy(fn (Invoice $invoice): int => $invoiceIds->search($invoice->getKey()))
            ->values();

        foreach ($invoices as $invoice) {
            Gate::authorize('view', $invoice);
        }

        $created = $invoices->map(function (Invoice $invoice) use ($money): array {
            $line = $invoice->lines->first();

            return [
                'id' => (int) $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'company' => $invoice->company?->name ?? $invoice->payer_name,
                'contract' => $invoice->contract?->contract_number ?? $invoice->contract_reference,
                'period' => $this->invoicePeriodLabel($line),
                'total_display' => $money->formatMinorUnits(
                    $money->toMinorUnits($invoice->total_amount)
                ),
                'status' => $invoice->status,
            ];
        })->all();

        return view('invoices.billing-result', [
            'period' => $period,
            'created' => $created,
            'skipped' => is_array($context['skipped'] ?? null) ? $context['skipped'] : [],
        ]);
    }

    public function storeDrafts(
        Request $request,
        CreateBillingRunDrafts $createDrafts,
    ): \Illuminate\Http\RedirectResponse {
        Gate::authorize('create', Invoice::class);

        $period = $this->periodFromRequest($request);
        $validated = $request->validate([
            'selected_occurrences' => ['required', 'array', 'min:1', 'max:100'],
            'selected_occurrences.*' => ['required', 'string', 'regex:/^\d+:[a-f0-9]{64}$/'],
        ]);
        $result = $createDrafts->execute(
            $period,
            array_values($validated['selected_occurrences']),
            $request->user(),
        );

        $request->session()->put(self::RESULT_SESSION_KEY, [
            'user_id' => (int) $request->user()->getKey(),
            'month' => $period->month,
            'year' => $period->year,
            'created_invoice_ids' => collect($result['created'])
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
            'skipped' => $result['skipped'],
        ]);

        return redirect()
            ->route('invoices.billing.result');
    }

    private function render(Request $request, BillingPeriodPreview $preview): View
    {
        Gate::authorize('create', Invoice::class);

        $period = $this->periodFromRequest($request);
        $result = $preview->forPeriod($period);
        $months = [];

        for ($index = 1; $index <= 12; $index++) {
            $monthDate = CarbonImmutable::create($period->year, $index, 1)->locale(app()->getLocale());
            $months[] = [
                'value' => $index,
                'label' => $monthDate->translatedFormat('F'),
            ];
        }

        return view('invoices.billing', [
            'period' => $period,
            'months' => $months,
            'years' => range($period->year - 5, $period->year + 5),
            'preview' => $result,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function periodFromContext(array $context): CarbonImmutable
    {
        $month = $this->validatedInteger($context['month'] ?? null, 1, 1, 12);
        $year = $this->validatedInteger($context['year'] ?? null, 2000, 2000, 2100);

        return CarbonImmutable::create($year, $month, 1)->startOfDay();
    }

    private function invoicePeriodLabel(?object $line): string
    {
        if ($line?->period_start === null || $line?->period_end === null) {
            return '—';
        }

        return CarbonImmutable::parse($line->period_start)->format('d/m/Y')
            .' — '
            .CarbonImmutable::parse($line->period_end)->format('d/m/Y');
    }

    private function periodFromRequest(Request $request): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $month = $this->validatedInteger($request->query('month'), (int) $now->month, 1, 12);
        $year = $this->validatedInteger($request->query('year'), (int) $now->year, 2000, 2100);

        return CarbonImmutable::create($year, $month, 1)->startOfDay();
    }

    private function validatedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($integer !== false && $integer >= $minimum && $integer <= $maximum, 422);

        return (int) $integer;
    }
}
