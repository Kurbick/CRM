<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\CreateInvoice;
use App\Actions\Invoices\DeleteInvoice;
use App\Actions\Invoices\UpdateInvoice;
use App\Exceptions\Invoices\InvoiceDeletionException;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\ApiPagination;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use LogicException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly UpdateInvoice $updateInvoice,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $fields = [
            'id',
            'company_id',
            'contract_id',
            'invoice_number',
            'issue_date',
            'due_date',
            'status',
            'total_amount',
            'created_at',
            'updated_at',
        ];
        $invoices = $company->invoices()
            ->select($fields)
            ->withSum([
                'payments as confirmed_paid_amount' => fn ($query) => $query
                    ->where('status', 'confirmed'),
            ], 'amount')
            ->orderBy('id')
            ->paginate(
                ApiPagination::perPage($request),
                $fields,
                'page',
                ApiPagination::page($request),
            );

        return response()->json(ApiPagination::envelope(
            $invoices,
            fn (Invoice $invoice): array => $this->compactProjection(
                $invoice,
                $invoice->getAttribute('confirmed_paid_amount')
            ),
        ));
    }

    public function store(StoreInvoiceRequest $request, Company $company): JsonResponse
    {
        $validated = $request->validated();
        $contract = Contract::query()->whereKey($validated['contract_id'])->firstOrFail();
        $invoice = $this->createInvoice->execute(
            $company,
            $contract,
            $validated,
            array_values($validated['lines']),
            actor: $request->user(),
        );

        $invoice->load(['company', 'contract', 'lines']);

        return response()->json($this->detailProjection(
            $invoice,
            $invoice->company,
            $invoice->contract,
            $invoice->lines,
            '0.00',
        ), 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);

        return response()->json($this->detailProjectionFor($invoice));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validated();
        $lines = null;

        if (array_key_exists('lines', $validated)) {
            $lines = array_values($validated['lines']);
            unset($validated['lines']);
        }

        $invoice = $this->updateInvoice->execute($invoice, $validated, $lines);

        return response()->json($this->detailProjectionFor($invoice));
    }

    public function destroy(Request $request, Invoice $invoice, DeleteInvoice $deleteInvoice): JsonResponse
    {
        Gate::authorize('delete', $invoice);

        try {
            $deleteInvoice->execute($invoice, $request->user());
        } catch (InvoiceDeletionException $exception) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'invoice' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json(['message' => 'Инвойс удалён'], 200);
    }

    /** @return array<string, mixed> */
    private function compactProjection(Invoice $invoice, mixed $confirmedPaidAmount): array
    {
        return [
            'id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'contract_id' => $invoice->contract_id,
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $this->dateValue($invoice->issue_date),
            'due_date' => $this->dateValue($invoice->due_date),
            'status' => $invoice->status,
            'total_amount' => $this->decimalValue($invoice->total_amount),
            'paid_amount' => $this->decimalValue($confirmedPaidAmount),
            'remaining_amount' => $this->remainingAmount($invoice->total_amount, $confirmedPaidAmount),
            'is_overdue' => $invoice->is_overdue,
            'created_at' => $invoice->created_at?->toJSON(),
            'updated_at' => $invoice->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailProjectionFor(Invoice $invoice): array
    {
        $company = $invoice->company()
            ->select(['companies.id', 'companies.name', 'companies.short_name'])
            ->firstOrFail();
        $contract = $invoice->contract_id === null
            ? null
            : $invoice->contract()
                ->select(['contracts.id', 'contracts.company_id', 'contracts.contract_number'])
                ->first();
        $lines = $invoice->lines()
            ->select([
                'invoice_lines.id',
                'invoice_lines.invoice_id',
                'invoice_lines.description',
                'invoice_lines.amount',
                'invoice_lines.period_start',
                'invoice_lines.period_end',
            ])
            ->orderBy('invoice_lines.id')
            ->get();
        $confirmedPaidAmount = $invoice->payments()
            ->where('status', 'confirmed')
            ->sum('amount');

        return $this->detailProjection($invoice, $company, $contract, $lines, $confirmedPaidAmount);
    }

    /**
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<string, mixed>
     */
    private function detailProjection(
        Invoice $invoice,
        Company $company,
        ?Contract $contract,
        Collection $lines,
        mixed $confirmedPaidAmount,
    ): array {
        return [
            'id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'contract_id' => $invoice->contract_id,
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $this->dateValue($invoice->issue_date),
            'due_date' => $this->dateValue($invoice->due_date),
            'period_start' => $this->dateValue($invoice->period_start),
            'period_end' => $this->dateValue($invoice->period_end),
            'status' => $invoice->status,
            'total_amount' => $this->decimalValue($invoice->total_amount),
            'paid_amount' => $this->decimalValue($confirmedPaidAmount),
            'remaining_amount' => $this->remainingAmount($invoice->total_amount, $confirmedPaidAmount),
            'is_overdue' => $invoice->is_overdue,
            'comment' => $invoice->comment,
            'seller_name' => $invoice->seller_name,
            'seller_voen' => $invoice->seller_voen,
            'seller_bank_name' => $invoice->seller_bank_name,
            'seller_iban' => $invoice->seller_iban,
            'seller_bank_code' => $invoice->seller_bank_code,
            'seller_bank_voen' => $invoice->seller_bank_voen,
            'seller_swift' => $invoice->seller_swift,
            'payer_name' => $invoice->payer_name,
            'payer_voen' => $invoice->payer_voen,
            'contract_reference' => $invoice->contract_reference,
            'created_at' => $invoice->created_at?->toJSON(),
            'updated_at' => $invoice->updated_at?->toJSON(),
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'short_name' => $company->short_name,
            ],
            'contract' => $contract === null ? null : [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ],
            'lines' => $lines
                ->map(fn (InvoiceLine $line): array => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'amount' => $this->decimalValue($line->amount),
                    'period_start' => $this->dateValue($line->period_start),
                    'period_end' => $this->dateValue($line->period_end),
                ])
                ->values()
                ->all(),
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

    private function remainingAmount(mixed $totalAmount, mixed $paidAmount): string
    {
        return $this->formatMinorUnits(max(
            $this->toMinorUnits($totalAmount) - $this->toMinorUnits($paidAmount),
            0
        ));
    }

    private function decimalValue(mixed $value): string
    {
        return $this->formatMinorUnits($this->toMinorUnits($value));
    }

    private function toMinorUnits(mixed $value): int
    {
        $decimal = trim((string) ($value ?? '0'));
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $decimal, $matches) !== 1) {
            throw new LogicException("Invalid Invoice decimal value [{$decimal}].");
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinorUnits(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
