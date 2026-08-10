<?php

namespace App\Http\Controllers;

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreatePendingPayment;
use App\Exceptions\Payments\PaymentConfirmationException;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationWriter;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly InvoicePaymentAllocationWriter $allocationWriter,
        private readonly CreatePendingPayment $createPendingPayment
    ) {}

    /**
     * Все платежи по конкретному инвойсу.
     */
    public function index(Invoice $invoice): JsonResponse
    {
        Gate::authorize('viewAny', [Payment::class, $invoice]);

        $payments = $invoice->payments()
            ->select([
                'id',
                'invoice_id',
                'amount',
                'payment_date',
                'payment_method',
                'status',
                'comment',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $payment): array => $this->paymentProjection($payment))
            ->values()
            ->all();

        return response()->json(
            $payments
        );
    }

    public function store(StorePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $payment = $this->createPendingPayment->execute($invoice, $request->validated());

        return response()->json($this->paymentProjection($payment), 201);
    }

    /**
     * Показать один платёж.
     */
    public function show(Payment $payment): JsonResponse
    {
        Gate::authorize('view', $payment);

        return response()->json($this->paymentProjection($payment));
    }

    public function confirm(
        ConfirmPaymentRequest $request,
        Payment $payment,
        ConfirmPayment $confirmPayment
    ): JsonResponse {
        try {
            $confirmedPayment = $confirmPayment->execute($payment);
        } catch (PaymentConfirmationException $exception) {
            throw ValidationException::withMessages([
                'payment' => $exception->getMessage(),
            ]);
        }

        return response()->json($this->paymentProjection($confirmedPayment));
    }

    /**
     * Обновить платёж — например подтвердить pending.
     * После обновления статус инвойса пересчитается автоматически.
     */
    public function update(UpdatePaymentRequest $request, Payment $payment): JsonResponse
    {
        DB::transaction(function () use ($request, $payment): void {
            $invoice = Invoice::query()
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $wasConfirmed = $lockedPayment->status === 'confirmed';

            $lockedPayment->update($request->validated());

            if ($wasConfirmed || $lockedPayment->status === 'confirmed') {
                $this->allocationWriter->synchronize($invoice);
            }

            $payment->setRawAttributes($lockedPayment->getAttributes(), true);
        });

        $invoice = $payment->invoice->fresh();
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json([
            'payment' => $payment,
            'invoice' => $invoice,
        ]);
    }

    /**
     * Удалить платёж.
     * После удаления статус инвойса нужно пересчитать вручную —
     * booted() срабатывает только при сохранении, не при удалении.
     */
    public function destroy(Payment $payment): JsonResponse
    {
        $invoice = $payment->invoice;
        $payment->delete();

        // Пересчитываем статус инвойса вручную после удаления платежа
        $paidAmount = $invoice->payments()
            ->where('status', 'confirmed')
            ->sum('amount');

        if ($paidAmount <= 0) {
            $invoice->update(['status' => 'issued']);
        } elseif ($paidAmount < $invoice->total_amount) {
            $invoice->update(['status' => 'partially_paid']);
        }

        return response()->json(['message' => 'Платёж удалён'], 200);
    }

    /** @return array<string, mixed> */
    private function paymentProjection(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'invoice_id' => (int) $payment->invoice_id,
            'amount' => $this->decimalValue($payment->amount),
            'payment_date' => $this->dateValue($payment->payment_date),
            'payment_method' => $payment->payment_method,
            'status' => $payment->status,
            'comment' => $payment->comment,
            'created_at' => $payment->created_at?->toJSON(),
            'updated_at' => $payment->updated_at?->toJSON(),
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

    private function decimalValue(mixed $value): string
    {
        $decimal = trim((string) $value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $decimal, $matches) !== 1) {
            throw new LogicException("Invalid Payment decimal value [{$decimal}].");
        }

        $minorUnits = ((int) $matches[2] * 100)
            + (int) str_pad($matches[3] ?? '', 2, '0');
        $sign = $matches[1] === '-' && $minorUnits !== 0 ? '-' : '';

        return $sign.sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
