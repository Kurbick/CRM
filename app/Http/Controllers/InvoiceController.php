<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    
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
        $invoice->update($request->validated());
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json($invoice);
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