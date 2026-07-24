<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceEditabilityService
{
    /**
     * @return array{editable: bool, reason: null|'invalid_status'|'confirmed_payment'|'cancelled', has_pending_payments: bool, has_confirmed_payments: bool}
     */
    public function evaluate(Invoice $invoice): array
    {
        $hasPendingPayments = $this->hasPaymentWithStatus($invoice, 'pending');
        $hasConfirmedPayments = $this->hasPaymentWithStatus($invoice, 'confirmed');

        if ($invoice->status === 'cancelled') {
            return $this->result(false, 'cancelled', $hasPendingPayments, $hasConfirmedPayments);
        }

        if (!in_array($invoice->status, ['draft', 'issued'], true)) {
            return $this->result(false, 'invalid_status', $hasPendingPayments, $hasConfirmedPayments);
        }

        if ($hasConfirmedPayments) {
            return $this->result(false, 'confirmed_payment', $hasPendingPayments, true);
        }

        return $this->result(true, null, $hasPendingPayments, false);
    }

    private function hasPaymentWithStatus(Invoice $invoice, string $status): bool
    {
        if ($invoice->relationLoaded('payments')) {
            return $invoice->payments->contains('status', $status);
        }

        return $invoice->payments()->where('status', $status)->exists();
    }

    /**
     * @return array{editable: bool, reason: null|'invalid_status'|'confirmed_payment'|'cancelled', has_pending_payments: bool, has_confirmed_payments: bool}
     */
    private function result(
        bool $editable,
        ?string $reason,
        bool $hasPendingPayments,
        bool $hasConfirmedPayments
    ): array {
        return [
            'editable' => $editable,
            'reason' => $reason,
            'has_pending_payments' => $hasPendingPayments,
            'has_confirmed_payments' => $hasConfirmedPayments,
        ];
    }
}
