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
        array $subscriptionIds
    ): string {
        $paymentTerms = $this->resolvePaymentTerms(
            contractId: $contractId,
            orderIds: $orderIds,
            subscriptionIds: $subscriptionIds
        );

        $issueDateValue = Carbon::parse($issueDate)->startOfDay();

        if ($paymentTerms !== null) {
            return $issueDateValue->copy()->addDays($paymentTerms)->toDateString();
        }

        if (!$manualDueDate) {
            throw ValidationException::withMessages([
                'due_date' => 'Укажите срок оплаты для инвойса без условий оплаты в позициях.',
            ]);
        }

        $manualDueDateValue = Carbon::parse($manualDueDate)->startOfDay();

        if ($manualDueDateValue->lt($issueDateValue)) {
            throw ValidationException::withMessages([
                'due_date' => 'Срок оплаты не может быть раньше даты выставления.',
            ]);
        }

        return $manualDueDateValue->toDateString();
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
