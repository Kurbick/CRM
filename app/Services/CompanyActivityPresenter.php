<?php

namespace App\Services;

use App\Enums\SubscriptionBillingPeriod;
use App\Models\CompanyActivityEvent;
use App\Models\User;
use App\Support\CompanyActivityEventType;
use Carbon\CarbonImmutable;

final class CompanyActivityPresenter
{
    /** @param array{contacts: array<int, bool>, contracts: array<int, bool>, invoices: array<int, bool>, document_contracts: array<int, int>} $availableSubjects */
    public function present(CompanyActivityEvent $event, User $user, array $availableSubjects = []): array
    {
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $type = CompanyActivityEventType::tryFrom((string) $event->event_type);
        $subjectId = $event->subject_id === null ? null : (int) $event->subject_id;

        [$title, $context, $icon, $tone] = $this->copy($type, $metadata);

        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'title' => $title,
            'context' => $context,
            'icon' => $icon,
            'tone' => $tone,
            'subject_url' => $this->subjectUrl($event, $user, $availableSubjects),
            'context_url' => $this->contextUrl($event, $metadata, $availableSubjects),
            'time_label' => $this->timeLabel($event->occurred_at),
            'actor_label' => $event->relationLoaded('actor') && $event->actor
                ? $event->actor->name
                : 'Система',
        ];
    }

    /** @return array{0: string, 1: ?string, 2: string, 3: string} */
    private function copy(?CompanyActivityEventType $type, array $metadata): array
    {
        $amount = $this->amount($metadata);
        $invoiceNumber = $this->text($metadata, 'invoice_number');
        $contractNumber = $this->text($metadata, 'contract_number');
        $subjectName = $this->text($metadata, 'subject_name');
        $contactName = $this->text($metadata, 'contact_name');
        $documentName = $this->text($metadata, 'document_name');

        return match ($type) {
            CompanyActivityEventType::CompanyCreated => ['Компания создана', null, 'company', 'blue'],
            CompanyActivityEventType::CompanyUpdated => ['Данные компании обновлены', null, 'company', 'slate'],
            CompanyActivityEventType::ContactCreated => [
                $contactName === null ? 'Добавлен контакт' : 'Добавлен контакт '.$contactName,
                $this->contactContext($metadata),
                'contact-created',
                'blue',
            ],
            CompanyActivityEventType::ContactUpdated => [
                $contactName === null ? 'Контакт изменён' : 'Контакт '.$contactName.' изменён',
                $this->contactContext($metadata),
                'contact-updated',
                'blue',
            ],
            CompanyActivityEventType::ContactDeleted => [
                $contactName === null ? 'Удалён контакт' : 'Удалён контакт '.$contactName,
                $this->contactContext($metadata),
                'contact-deleted',
                'red',
            ],
            CompanyActivityEventType::ContractCreated => [
                $contractNumber === null ? 'Договор создан' : 'Создан договор '.$contractNumber,
                $this->contractDates($metadata),
                'contract',
                'blue',
            ],
            CompanyActivityEventType::ContractStatusChanged => [
                $contractNumber === null
                    ? 'Статус договора изменён'
                    : 'Статус договора '.$contractNumber.' изменён',
                $this->statusChange($metadata),
                'contract',
                'slate',
            ],
            CompanyActivityEventType::ContractDeleted => [
                $contractNumber === null ? 'Договор удалён' : 'Удалён договор '.$contractNumber,
                $this->contractDates($metadata),
                'contract-deleted',
                'red',
            ],
            CompanyActivityEventType::ContractSubjectCreated => $this->subjectCreated($metadata),
            CompanyActivityEventType::ContractSubjectUpdated => $this->subjectChanged($metadata, 'изменена', 'slate'),
            CompanyActivityEventType::ContractSubjectDeleted => $this->subjectChanged($metadata, 'удалена', 'red'),
            CompanyActivityEventType::DocumentUploaded => [
                $documentName === null ? 'Документ загружен' : 'Загружен документ '.$documentName,
                $contractNumber === null ? null : 'Договор '.$contractNumber,
                'document',
                'blue',
            ],
            CompanyActivityEventType::DocumentDeleted => [
                $documentName === null ? 'Документ удалён' : 'Удалён документ '.$documentName,
                $contractNumber === null ? null : 'Договор '.$contractNumber,
                'document-deleted',
                'red',
            ],
            CompanyActivityEventType::InvoiceCreated => [
                $invoiceNumber === null ? 'Создан черновик инвойса' : 'Создан черновик инвойса '.$invoiceNumber,
                $this->joinContext($contractNumber, $amount),
                'invoice',
                'blue',
            ],
            CompanyActivityEventType::InvoiceIssued => [
                $invoiceNumber === null ? 'Инвойс выставлен' : 'Инвойс '.$invoiceNumber.' выставлен',
                $this->joinContext($contractNumber, $amount),
                'invoice',
                'blue',
            ],
            CompanyActivityEventType::InvoiceCancelled => [
                $invoiceNumber === null ? 'Инвойс отменён' : 'Инвойс '.$invoiceNumber.' отменён',
                $this->joinContext($contractNumber, $amount),
                'invoice-cancelled',
                'red',
            ],
            CompanyActivityEventType::InvoiceDeleted => [
                $this->deletedInvoiceTitle($invoiceNumber, $metadata),
                $this->joinContext($contractNumber, $amount),
                'invoice-deleted',
                'red',
            ],
            CompanyActivityEventType::PaymentPendingCreated => [
                $amount === null
                    ? 'Платёж ожидает подтверждения'
                    : 'Платёж '.$amount.' ожидает подтверждения',
                $invoiceNumber,
                'payment',
                'amber',
            ],
            CompanyActivityEventType::PaymentConfirmed => [$this->paymentTitle($amount, 'подтверждён'), $this->joinContext($invoiceNumber, $this->method($metadata)), 'payment-confirmed', 'green'],
            CompanyActivityEventType::PaymentCancelled => [
                $this->paymentTitle($amount, 'отменён'),
                $this->reason($metadata) ?? $invoiceNumber,
                'payment-cancelled',
                'red',
            ],
            CompanyActivityEventType::CreditApplied => [
                $amount === null ? 'Из баланса применено' : 'Из баланса применено '.$amount,
                $invoiceNumber,
                'credit-applied',
                'blue',
            ],
            default => ['Событие компании', null, 'company', 'slate'],
        };
    }

    /** @return array{0: string, 1: ?string, 2: string, 3: string} */
    private function subjectCreated(array $metadata): array
    {
        $subjectName = $this->text($metadata, 'subject_name');
        $subjectType = $this->text($metadata, 'subject_type');

        // ACT-1 demo snapshots predate subject_type; billing_period is a safe
        // structured subscription signal for those immutable rows.
        if ($subjectType === null && $this->text($metadata, 'billing_period') !== null) {
            $subjectType = 'subscription';
        }

        $title = match ($subjectType) {
            'subscription' => $subjectName === null
                ? 'Добавлен предмет договора'
                : 'Добавлена подписка '.$subjectName,
            'one_time' => $subjectName === null
                ? 'Добавлен предмет договора'
                : 'Добавлена разовая услуга '.$subjectName,
            default => 'Добавлен предмет договора',
        };

        $contract = $this->text($metadata, 'contract_number');
        $amount = $this->amount($metadata);
        $billingPeriod = $this->billingPeriod($metadata);

        if ($subjectType === 'subscription' && $billingPeriod !== null && $amount !== null) {
            $amount .= ' / '.$billingPeriod;
        }

        return [$title, $this->joinContext($contract === null ? null : 'Договор '.$contract, $amount), 'subject', 'blue'];
    }

    /** @return array{0: string, 1: ?string, 2: string, 3: string} */
    private function subjectChanged(array $metadata, string $verb, string $tone): array
    {
        $subjectName = $this->text($metadata, 'subject_name');
        $subjectType = $this->text($metadata, 'subject_type');
        $title = match ($subjectType) {
            'subscription' => $subjectName === null
                ? 'Подписка '.$verb
                : 'Подписка '.$subjectName.' '.$verb,
            'one_time' => $subjectName === null
                ? 'Разовая услуга '.$verb
                : 'Разовая услуга '.$subjectName.' '.$verb,
            default => $subjectName === null
                ? 'Предмет договора '.$verb
                : 'Предмет договора '.$subjectName.' '.$verb,
        };

        $contract = $this->text($metadata, 'contract_number');
        $amount = $this->amount($metadata);
        $billingPeriod = $this->billingPeriod($metadata);
        if ($subjectType === 'subscription' && $billingPeriod !== null && $amount !== null) {
            $amount .= ' / '.$billingPeriod;
        }

        return [$title, $this->joinContext($contract === null ? null : 'Договор '.$contract, $amount), $tone === 'red' ? 'subject-deleted' : 'subject-updated', $tone];
    }

    private function subjectUrl(CompanyActivityEvent $event, User $user, array $availableSubjects): ?string
    {
        if ($event->event_type === CompanyActivityEventType::InvoiceDeleted->value) {
            return null;
        }

        $id = $event->subject_id === null ? null : (int) $event->subject_id;
        if ($id === null) {
            return null;
        }

        return match ($event->subject_type) {
            'contact' => ($availableSubjects['contacts'][$id] ?? false)
                && $user->can('company_contacts.update')
                ? route('contacts.edit', ['contact' => $id, 'origin' => 'company', 'tab' => 'activity'])
                : null,
            'contract' => ($availableSubjects['contracts'][$id] ?? false)
                ? route('contracts.show', ['contract' => $id, 'origin' => 'company', 'tab' => 'activity'])
                : null,
            'invoice' => ($availableSubjects['invoices'][$id] ?? false)
                ? route('invoices.show', ['invoice' => $id, 'origin' => 'company', 'tab' => 'activity'])
                : null,
            'document' => isset($availableSubjects['document_contracts'][$id])
                ? route('contracts.show', ['contract' => $availableSubjects['document_contracts'][$id], 'origin' => 'company', 'tab' => 'activity'])
                : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $metadata */
    private function contextUrl(CompanyActivityEvent $event, array $metadata, array $availableSubjects): ?string
    {
        $invoiceId = $metadata['invoice_id'] ?? null;
        if (is_numeric($invoiceId) && ($availableSubjects['invoices'][(int) $invoiceId] ?? false)) {
            return route('invoices.show', ['invoice' => (int) $invoiceId, 'origin' => 'company', 'tab' => 'activity']);
        }

        return null;
    }

    private function timeLabel(?\DateTimeInterface $occurredAt): string
    {
        if ($occurredAt === null) {
            return '—';
        }

        $timezone = (string) config('app.display_timezone', 'Asia/Baku');
        $date = CarbonImmutable::instance($occurredAt)->setTimezone($timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if ($date->isSameDay($today)) {
            return 'Сегодня, '.$date->format('H:i');
        }
        if ($date->isSameDay($today->subDay())) {
            return 'Вчера, '.$date->format('H:i');
        }

        return $date->format('d/m/Y, H:i');
    }

    private function text(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function amount(array $metadata): ?string
    {
        $minor = $metadata['amount_minor'] ?? null;
        if (! is_numeric($minor)) {
            return null;
        }

        $minor = (int) $minor;
        $currency = $this->text($metadata, 'currency') ?? '₼';

        return number_format($minor / 100, 2, ',', ' ').' '.$currency;
    }

    private function paymentTitle(?string $amount, string $suffix): string
    {
        return $amount === null ? 'Платёж '.$suffix : 'Платёж '.$amount.' '.$suffix;
    }

    private function contactContext(array $metadata): ?string
    {
        return $this->joinContext(
            $this->text($metadata, 'position'),
            $this->text($metadata, 'phone') ?? $this->text($metadata, 'email'),
        ) ?? $this->text($metadata, 'contact_name');
    }

    private function deletedInvoiceTitle(?string $invoiceNumber, array $metadata): string
    {
        $invoice = $invoiceNumber === null ? 'Инвойс' : 'Инвойс '.$invoiceNumber;

        return ($this->text($metadata, 'status') ?? '') === 'draft'
            ? str_replace('Инвойс', 'Удалён черновик инвойса', $invoice)
            : $invoice.' удалён';
    }

    private function billingPeriod(array $metadata): ?string
    {
        return match (SubscriptionBillingPeriod::tryFrom((string) ($metadata['billing_period'] ?? ''))) {
            SubscriptionBillingPeriod::Monthly => 'ежемесячно',
            SubscriptionBillingPeriod::Quarterly => 'ежеквартально',
            SubscriptionBillingPeriod::Semiannual => 'раз в полгода',
            SubscriptionBillingPeriod::Annual => 'ежегодно',
            SubscriptionBillingPeriod::Custom => 'индивидуальный период',
            null => null,
        };
    }

    private function method(array $metadata): ?string
    {
        return match ($this->text($metadata, 'payment_method')) {
            'transfer' => 'Безналичный',
            'card' => 'Карта',
            'cash' => 'Наличные',
            default => $this->text($metadata, 'payment_method'),
        };
    }

    private function reason(array $metadata): ?string
    {
        $reason = $this->text($metadata, 'reason');

        return $reason === null ? null : 'Причина: '.$reason;
    }

    private function statusChange(array $metadata): ?string
    {
        $old = $this->text($metadata, 'old_status');
        $new = $this->text($metadata, 'new_status');

        return $old !== null && $new !== null
            ? $this->statusLabel($old).' → '.$this->statusLabel($new)
            : null;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Активен',
            'terminated' => 'Завершён',
            'expired' => 'Истёк',
            'suspended' => 'Приостановлен',
            'completed' => 'Завершён',
            'cancelled' => 'Отменён',
            default => $status,
        };
    }

    private function contractDates(array $metadata): ?string
    {
        $start = $this->date($metadata['start_date'] ?? null);
        $end = $this->date($metadata['end_date'] ?? null);

        return $start !== null && $end !== null
            ? $start.' — '.$end
            : ($start ?? $end);
    }

    private function date(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('d/m/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private function joinContext(?string ...$parts): ?string
    {
        $parts = array_values(array_filter($parts));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
