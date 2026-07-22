<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;

class InvoiceDueDateSynchronizer
{
    private const OPEN_INVOICE_STATUSES = [
        'draft',
        'issued',
        'partially_paid',
    ];

    public function __construct(
        private readonly InvoiceDueDateCalculator $calculator
    ) {
    }

    public function synchronizeForOrder(Order $order): void
    {
        $this->synchronizeOpenInvoices('order_id', $order->getKey());
    }

    public function synchronizeForSubscription(Subscription $subscription): void
    {
        $this->synchronizeOpenInvoices('subscription_id', $subscription->getKey());
    }

    private function synchronizeOpenInvoices(string $foreignKey, int $subjectId): void
    {
        $invoices = Invoice::query()
            ->whereIn('status', self::OPEN_INVOICE_STATUSES)
            ->whereHas('lines', fn($query) => $query->where($foreignKey, $subjectId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            $lines = $invoice->lines()->get(['order_id', 'subscription_id']);

            $dueDate = $this->calculator->calculate(
                issueDate: $invoice->issue_date,
                manualDueDate: $invoice->due_date,
                contractId: (int) $invoice->contract_id,
                orderIds: $lines->pluck('order_id')->filter()->all(),
                subscriptionIds: $lines->pluck('subscription_id')->filter()->all()
            );

            $invoice->update(['due_date' => $dueDate]);
        }
    }
}
