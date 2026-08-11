<?php

namespace App\Http\Controllers;

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreatePendingPayment;
use App\Exceptions\Payments\PaymentConfirmationException;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ApiPagination;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CreatePendingPayment $createPendingPayment
    ) {}

    /**
     * Все платежи по конкретному инвойсу.
     */
    public function index(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('viewAny', [Payment::class, $invoice]);

        $fields = [
            'id',
            'invoice_id',
            'amount',
            'payment_date',
            'payment_method',
            'status',
            'comment',
            'created_at',
            'updated_at',
        ];
        $payments = $invoice->payments()
            ->select($fields)
            ->orderBy('id')
            ->paginate(
                ApiPagination::perPage($request),
                $fields,
                'page',
                ApiPagination::page($request),
            );

        return response()->json(ApiPagination::envelope(
            $payments,
            fn (Payment $payment): array => $this->paymentProjection($payment),
        ));
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
