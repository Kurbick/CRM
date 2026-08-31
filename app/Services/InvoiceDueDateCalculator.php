<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InvoiceDueDateCalculator
{
    public function calculate(
        string $issueDate,
        ?string $manualDueDate,
        int $contractId,
        array $orderIds,
        array $subscriptionIds,
        ?string $contractEndDate = null,
    ): string {
        $issueDateValue = Carbon::parse($issueDate)->startOfDay();
        $contractEnd = $contractEndDate !== null
            ? Carbon::parse($contractEndDate)->startOfDay()
            : null;

        if ($contractEnd && $issueDateValue->gt($contractEnd)) {
            throw ValidationException::withMessages([
                'issue_date' => __('invoices.errors.issue_date_after_contract_end', [
                    'contract_end_date' => $contractEnd->format('d/m/Y'),
                ]),
            ]);
        }

        $paymentTerms = $this->resolvePaymentTerms(
            contractId: $contractId,
            orderIds: $orderIds,
            subscriptionIds: $subscriptionIds
        );

        if ($paymentTerms !== null) {
            $dueDate = $issueDateValue->copy()->addDays($paymentTerms);
        } elseif (!$manualDueDate) {
            throw ValidationException::withMessages([
                'due_date' => 'Укажите срок оплаты для инвойса без условий оплаты в позициях.',
            ]);
        } else {
            $manualDueDateValue = Carbon::parse($manualDueDate)->startOfDay();

            if ($manualDueDateValue->lt($issueDateValue)) {
                throw ValidationException::withMessages([
                    'due_date' => 'Срок оплаты не может быть раньше даты выставления.',
                ]);
            }

            $dueDate = $manualDueDateValue;
        }

        if ($contractEnd && $dueDate->gt($contractEnd)) {
            $parameters = [
                'due_date' => $dueDate->format('d/m/Y'),
                'contract_end_date' => $contractEnd->format('d/m/Y'),
            ];
            if ($paymentTerms !== null) {
                $parameters['days'] = $paymentTerms;
            }

            throw ValidationException::withMessages([
                'due_date' => __(
                    $paymentTerms === null
                        ? 'invoices.errors.due_date_after_contract_end_manual'
                        : 'invoices.errors.due_date_after_contract_end',
                    $parameters,
                ),
            ]);
        }

        return $dueDate->toDateString();
    }

    private function resolvePaymentTerms(
        int $contractId,
        array $orderIds,
        array $subscriptionIds
    ): ?int {
        $orderIds = collect($orderIds)->filter()->map(fn($id) => (int) $id)->unique()->values();
        $subscriptionIds = collect($subscriptionIds)->filter()->map(fn($id) => (int) $id)->unique()->values();

        $orders = $orderIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('id', $orderIds)
                ->where('contract_id', $contractId)
                ->get(['id', 'title', 'payment_terms']);

        if ($orders->count() !== $orderIds->count()) {
            throw ValidationException::withMessages([
                'due_date' => 'Одна из разовых услуг не принадлежит договору инвойса.',
            ]);
        }

        $subscriptions = $subscriptionIds->isEmpty()
            ? collect()
            : Subscription::query()
                ->whereIn('id', $subscriptionIds)
                ->where('contract_id', $contractId)
                ->get(['id', 'payment_terms']);

        if ($subscriptions->count() !== $subscriptionIds->count()) {
            throw ValidationException::withMessages([
                'due_date' => 'Одна из подписок не принадлежит договору инвойса.',
            ]);
        }

        $orderTerms = $orders->map(function (Order $order): int {
            if ($order->payment_terms === null || $order->payment_terms === '') {
                throw ValidationException::withMessages([
                    'due_date' => "У разовой услуги «{$order->title}» не указан срок оплаты.",
                ]);
            }

            $terms = (int) $order->payment_terms;
            if ($terms < 0 || $terms > 3650) {
                throw ValidationException::withMessages([
                    'due_date' => 'Срок оплаты разовой услуги должен быть от 0 до 3650 дней.',
                ]);
            }

            return $terms;
        });

        $subscriptionTerms = $subscriptions->map(function (Subscription $subscription): int {
            $terms = (int) $subscription->payment_terms;
            if ($terms < 1 || $terms > 365) {
                throw ValidationException::withMessages([
                    'due_date' => 'Срок оплаты подписки должен быть от 1 до 365 дней.',
                ]);
            }

            return $terms;
        });

        $paymentTerms = $orderTerms->concat($subscriptionTerms);

        return $paymentTerms->isEmpty() ? null : $paymentTerms->min();
    }
}
