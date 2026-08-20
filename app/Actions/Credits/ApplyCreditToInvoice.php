<?php

namespace App\Actions\Credits;

use App\Actions\Payments\ApplyConfirmedPaymentLifecycle;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final class ApplyCreditToInvoice
{
    private const MAX_AMOUNT_MINOR = 9_999_999_999;

    public function __construct(
        private readonly InvoicePaymentAvailabilityService $money,
        private readonly ApplyConfirmedPaymentLifecycle $paymentLifecycle,
    ) {}

    public function execute(
        Invoice $invoice,
        ?int $requestedAmountMinor = null,
    ): AppliedCreditResult {
        $this->validateRequestedAmount($requestedAmountMinor);

        return $this->run(
            invoice: $invoice,
            requestedAmountMinor: $requestedAmountMinor,
            strict: false,
        );
    }

    /**
     * Apply an exact operator-requested amount using server-rendered financial
     * snapshots as stale-state guards.
     */
    public function executeManual(
        Invoice $invoice,
        int $requestedAmountMinor,
        int $expectedCreditBalanceMinor,
        int $expectedAvailableMinor,
    ): AppliedCreditResult {
        $this->validateRequestedAmount($requestedAmountMinor);

        if ($expectedCreditBalanceMinor < 0 || $expectedCreditBalanceMinor > self::MAX_AMOUNT_MINOR
            || $expectedAvailableMinor < 0 || $expectedAvailableMinor > self::MAX_AMOUNT_MINOR) {
            throw new InvalidArgumentException('Credit financial snapshot is outside the supported range.');
        }

        return $this->run(
            invoice: $invoice,
            requestedAmountMinor: $requestedAmountMinor,
            strict: true,
            expectedCreditBalanceMinor: $expectedCreditBalanceMinor,
            expectedAvailableMinor: $expectedAvailableMinor,
        );
    }

    private function run(
        Invoice $invoice,
        ?int $requestedAmountMinor,
        bool $strict,
        ?int $expectedCreditBalanceMinor = null,
        ?int $expectedAvailableMinor = null,
    ): AppliedCreditResult {
        return DB::transaction(function () use (
            $invoice,
            $requestedAmountMinor,
            $strict,
            $expectedCreditBalanceMinor,
            $expectedAvailableMinor,
        ): AppliedCreditResult {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedInvoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages([
                    'credit' => 'Credit Balance можно применить только к выставленному или частично оплаченному инвойсу.',
                ]);
            }

            $invoiceTotalMinor = $this->money->toMinorUnits($lockedInvoice->getRawOriginal('total_amount'));

            if ($invoiceTotalMinor < 1 || $invoiceTotalMinor > self::MAX_AMOUNT_MINOR) {
                throw new LogicException('Invoice total is outside the supported Credit application range.');
            }

            $availability = $this->money->evaluatePendingCreation($lockedInvoice);
            $unreservedRemainingMinor = $availability['available_minor'];

            if ($unreservedRemainingMinor === 0 && ! $strict) {
                return AppliedCreditResult::noOp(AppliedCreditResult::FULLY_RESERVED);
            }

            $creditBalance = CreditBalance::query()
                ->where('company_id', $lockedInvoice->company_id)
                ->lockForUpdate()
                ->first();

            $availableCreditMinor = 0;
            if ($creditBalance !== null) {
                if ((int) $creditBalance->company_id !== (int) $lockedInvoice->company_id) {
                    throw new LogicException('Credit Balance and Invoice companies do not match.');
                }

                $availableCreditMinor = $this->money->toMinorUnits(
                    $creditBalance->getRawOriginal('amount'),
                );

                if ($availableCreditMinor < 0 || $availableCreditMinor > self::MAX_AMOUNT_MINOR) {
                    throw new LogicException('Credit Balance amount is outside the supported range.');
                }
            }

            if ($strict && ($expectedAvailableMinor !== $unreservedRemainingMinor
                || $expectedCreditBalanceMinor !== $availableCreditMinor)) {
                throw ValidationException::withMessages([
                    'credit_amount' => 'Финансовые данные изменились. Обновите страницу и попробуйте снова.',
                ]);
            }

            if ($unreservedRemainingMinor === 0) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'credit_amount' => 'Весь остаток уже зарезервирован ожидающим платежом.',
                    ]);
                }

                return AppliedCreditResult::noOp(
                    AppliedCreditResult::FULLY_RESERVED,
                    $creditBalance?->getKey(),
                );
            }

            if ($creditBalance === null) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'credit_amount' => 'На балансе компании нет доступных средств.',
                    ]);
                }

                return AppliedCreditResult::noOp(AppliedCreditResult::NO_CREDIT_BALANCE);
            }

            if ($availableCreditMinor === 0) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'credit_amount' => 'На балансе компании нет доступных средств.',
                    ]);
                }

                return AppliedCreditResult::noOp(
                    AppliedCreditResult::ZERO_CREDIT,
                    (int) $creditBalance->getKey(),
                );
            }

            $safeMaximumMinor = min($availableCreditMinor, $unreservedRemainingMinor);
            $requestedMinor = $requestedAmountMinor ?? $safeMaximumMinor;

            if ($strict && $requestedMinor > $safeMaximumMinor) {
                throw ValidationException::withMessages([
                    'credit_amount' => 'Можно использовать не более '.$this->money->formatMinorUnits($safeMaximumMinor).'.',
                ]);
            }

            $appliedMinor = $strict
                ? $requestedMinor
                : min($requestedMinor, $safeMaximumMinor);

            if ($appliedMinor === 0) {
                return AppliedCreditResult::noOp(
                    AppliedCreditResult::FULLY_RESERVED,
                    (int) $creditBalance->getKey(),
                );
            }

            $appliedAmount = $this->money->fromMinorUnits($appliedMinor);
            $remainingCreditMinor = $availableCreditMinor - $appliedMinor;
            $entry = $creditBalance->entries()->create([
                'type' => 'applied',
                'amount' => $appliedAmount,
                'invoice_id' => $lockedInvoice->getKey(),
                'description' => ($strict ? 'Вручную применён' : 'Применён')
                    ." к инвойсу #{$lockedInvoice->invoice_number}",
            ]);

            $creditBalance->forceFill([
                'amount' => $this->money->fromMinorUnits($remainingCreditMinor),
            ])->saveQuietly();

            $payment = Payment::withoutEvents(fn (): Payment => Payment::query()->create([
                'company_id' => $lockedInvoice->company_id,
                'invoice_id' => $lockedInvoice->getKey(),
                'payment_date' => now()->toDateString(),
                'amount' => $appliedAmount,
                'payment_method' => 'transfer',
                'status' => 'confirmed',
                'comment' => ($strict ? 'Вручную применён' : 'Автоматически применён')
                    ." Credit Balance ({$appliedAmount} ₼)",
            ]));

            $entry->forceFill(['payment_id' => $payment->getKey()])->saveQuietly();
            $this->paymentLifecycle->execute($lockedInvoice, $payment);

            return AppliedCreditResult::applied(
                appliedAmountMinor: $appliedMinor,
                paymentId: (int) $payment->getKey(),
                entryId: (int) $entry->getKey(),
                creditBalanceId: (int) $creditBalance->getKey(),
            );
        });
    }

    private function validateRequestedAmount(?int $requestedAmountMinor): void
    {
        if ($requestedAmountMinor !== null
            && ($requestedAmountMinor < 1 || $requestedAmountMinor > self::MAX_AMOUNT_MINOR)) {
            throw new InvalidArgumentException('Requested Credit amount is outside the supported range.');
        }
    }
}
