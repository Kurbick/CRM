<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Services\InvoiceEditabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceEditabilityService $editabilityService
    ) {
    }
    
    public function index(Company $company): JsonResponse
    {
        $invoices = $company->invoices()
            ->with('lines')
            ->get()
            ->each(function ($invoice) {
                $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);
            });

        return response()->json($invoices);
    }

    
    public function store(StoreInvoiceRequest $request, Company $company): JsonResponse
    {
        $invoice = DB::transaction(function () use ($request, $company) {

            $invoice = $company->invoices()->create(
                $request->safe()->except('lines')
            );

            foreach ($request->validated('lines') as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice;
        });

        $invoice->load('lines');
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json($invoice, 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['company', 'lines', 'payments']);
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json($invoice);
    }

    
    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $invoice = DB::transaction(function () use ($request, $invoice): Invoice {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $editability = $this->editabilityService->evaluate($lockedInvoice);
            if (!$editability['editable']) {
                throw ValidationException::withMessages([
                    'invoice' => $this->editabilityMessage($editability['reason']),
                ]);
            }

            $lockedInvoice->update($request->validated());

            return $lockedInvoice;
        });

        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json($invoice);
    }

    private function editabilityMessage(?string $reason): string
    {
        return match ($reason) {
            'confirmed_payment' => 'Инвойс уже получил оплату и больше не может быть изменён.',
            'cancelled' => 'Отменённый инвойс нельзя редактировать.',
            default => 'Инвойс в текущем состоянии нельзя редактировать.',
        };
    }


    public function destroy(Invoice $invoice): JsonResponse
    {
        try {
            $invoice->delete();
            return response()->json(['message' => 'Инвойс удалён'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Невозможно удалить — по инвойсу есть платежи'
            ], 409);
        }
    }
}
