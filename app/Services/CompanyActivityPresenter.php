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
                : __('activity.actor.system'),
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
            CompanyActivityEventType::CompanyCreated => [__('activity.events.company_created'), null, 'company', 'blue'],
            CompanyActivityEventType::CompanyUpdated => [__('activity.events.company_updated'), null, 'company', 'slate'],
            CompanyActivityEventType::ContactCreated => [
                $contactName === null
                    ? __('activity.events.contact_created')
                    : __('activity.events.contact_created_named', ['name' => $contactName]),
                $this->contactContext($metadata),
                'contact-created',
                'blue',
            ],
            CompanyActivityEventType::ContactUpdated => [
                $contactName === null
                    ? __('activity.events.contact_updated')
                    : __('activity.events.contact_updated_named', ['name' => $contactName]),
                $this->contactContext($metadata),
                'contact-updated',
                'blue',
            ],
            CompanyActivityEventType::ContactDeleted => [
                $contactName === null
                    ? __('activity.events.contact_deleted')
                    : __('activity.events.contact_deleted_named', ['name' => $contactName]),
                $this->contactContext($metadata),
                'contact-deleted',
                'red',
            ],
            CompanyActivityEventType::ContractCreated => [
                $contractNumber === null
                    ? __('activity.events.contract_created')
                    : __('activity.events.contract_created_named', ['number' => $contractNumber]),
                $this->contractDates($metadata),
                'contract',
                'blue',
            ],
            CompanyActivityEventType::ContractStatusChanged => [
                $contractNumber === null
                    ? __('activity.events.contract_status_changed')
                    : __('activity.events.contract_status_changed_named', ['number' => $contractNumber]),
                $this->statusChange($metadata),
                'contract',
                'slate',
            ],
            CompanyActivityEventType::ContractDeleted => [
                $contractNumber === null
                    ? __('activity.events.contract_deleted')
                    : __('activity.events.contract_deleted_named', ['number' => $contractNumber]),
                $this->contractDates($metadata),
                'contract-deleted',
                'red',
            ],
            CompanyActivityEventType::ContractSubjectCreated => $this->subjectCreated($metadata),
            CompanyActivityEventType::ContractSubjectUpdated => $this->subjectChanged($metadata, 'updated', 'slate'),
            CompanyActivityEventType::ContractSubjectDeleted => $this->subjectChanged($metadata, 'deleted', 'red'),
            CompanyActivityEventType::DocumentUploaded => [
                $documentName === null
                    ? __('activity.events.document_uploaded')
                    : __('activity.events.document_uploaded_named', ['name' => $documentName]),
                $contractNumber === null ? null : __('activity.contexts.contract', ['number' => $contractNumber]),
                'document',
                'blue',
            ],
            CompanyActivityEventType::DocumentDeleted => [
                $documentName === null
                    ? __('activity.events.document_deleted')
                    : __('activity.events.document_deleted_named', ['name' => $documentName]),
                $contractNumber === null ? null : __('activity.contexts.contract', ['number' => $contractNumber]),
                'document-deleted',
                'red',
            ],
            CompanyActivityEventType::InvoiceCreated => [
                $invoiceNumber === null
                    ? __('activity.events.invoice_created')
                    : __('activity.events.invoice_created_named', ['number' => $invoiceNumber]),
                $this->joinContext($contractNumber, $amount),
                'invoice',
                'blue',
            ],
            CompanyActivityEventType::InvoiceIssued => [
                $invoiceNumber === null
                    ? __('activity.events.invoice_issued')
                    : __('activity.events.invoice_issued_named', ['number' => $invoiceNumber]),
                $this->joinContext($contractNumber, $amount),
                'invoice',
                'blue',
            ],
            CompanyActivityEventType::InvoiceCancelled => [
                $invoiceNumber === null
                    ? __('activity.events.invoice_cancelled')
                    : __('activity.events.invoice_cancelled_named', ['number' => $invoiceNumber]),
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
                    ? __('activity.events.payment_pending')
                    : __('activity.events.payment_pending_named', ['amount' => $amount]),
                $invoiceNumber,
                'payment',
                'amber',
            ],
            CompanyActivityEventType::PaymentConfirmed => [
                $amount === null
                    ? __('activity.events.payment_confirmed')
                    : __('activity.events.payment_confirmed_named', ['amount' => $amount]),
                $this->joinContext($invoiceNumber, $this->method($metadata)),
                'payment-confirmed',
                'green',
            ],
            CompanyActivityEventType::PaymentCancelled => [
                $amount === null
                    ? __('activity.events.payment_cancelled')
                    : __('activity.events.payment_cancelled_named', ['amount' => $amount]),
                $this->reason($metadata) ?? $invoiceNumber,
                'payment-cancelled',
                'red',
            ],
            CompanyActivityEventType::CreditApplied => [
                $amount === null
                    ? __('activity.events.credit_applied')
                    : __('activity.events.credit_applied_named', ['amount' => $amount]),
                $invoiceNumber,
                'credit-applied',
                'blue',
            ],
            default => [__('activity.events.fallback'), null, 'company', 'slate'],
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
                ? __('activity.events.subject_created_default')
                : __('activity.events.subject_created_subscription', ['name' => $subjectName]),
            'one_time' => $subjectName === null
                ? __('activity.events.subject_created_default')
                : __('activity.events.subject_created_one_time', ['name' => $subjectName]),
            default => __('activity.events.subject_created_default'),
        };

        $contract = $this->text($metadata, 'contract_number');
        $amount = $this->amount($metadata);
        $billingPeriod = $this->billingPeriod($metadata);

        if ($subjectType === 'subscription' && $billingPeriod !== null && $amount !== null) {
            $amount .= ' / '.$billingPeriod;
        }

        return [$title, $this->joinContext($contract === null ? null : __('activity.contexts.contract', ['number' => $contract]), $amount), 'subject', 'blue'];
    }

    /** @return array{0: string, 1: ?string, 2: string, 3: string} */
    private function subjectChanged(array $metadata, string $action, string $tone): array
    {
        $subjectName = $this->text($metadata, 'subject_name');
        $subjectType = $this->text($metadata, 'subject_type');
        $verb = __($action === 'updated' ? 'activity.verbs.updated' : 'activity.verbs.deleted');
        $title = match ($subjectType) {
            'subscription' => $subjectName === null
                ? __('activity.events.subject_changed_subscription', ['verb' => $verb])
                : __('activity.events.subject_changed_subscription_named', ['name' => $subjectName, 'verb' => $verb]),
            'one_time' => $subjectName === null
                ? __('activity.events.subject_changed_one_time', ['verb' => $verb])
                : __('activity.events.subject_changed_one_time_named', ['name' => $subjectName, 'verb' => $verb]),
            default => $subjectName === null
                ? __('activity.events.subject_changed_default', ['verb' => $verb])
                : __('activity.events.subject_changed_default_named', ['name' => $subjectName, 'verb' => $verb]),
        };

        $contract = $this->text($metadata, 'contract_number');
        $amount = $this->amount($metadata);
        $billingPeriod = $this->billingPeriod($metadata);
        if ($subjectType === 'subscription' && $billingPeriod !== null && $amount !== null) {
            $amount .= ' / '.$billingPeriod;
        }

        return [$title, $this->joinContext($contract === null ? null : __('activity.contexts.contract', ['number' => $contract]), $amount), $tone === 'red' ? 'subject-deleted' : 'subject-updated', $tone];
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
            return __('activity.time.today', ['time' => $date->format('H:i')]);
        }
        if ($date->isSameDay($today->subDay())) {
            return __('activity.time.yesterday', ['time' => $date->format('H:i')]);
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

    private function contactContext(array $metadata): ?string
    {
        return $this->joinContext(
            $this->text($metadata, 'position'),
            $this->text($metadata, 'phone') ?? $this->text($metadata, 'email'),
        ) ?? $this->text($metadata, 'contact_name');
    }

    private function deletedInvoiceTitle(?string $invoiceNumber, array $metadata): string
    {
        $isDraft = ($this->text($metadata, 'status') ?? '') === 'draft';

        if ($isDraft) {
            return $invoiceNumber === null
                ? __('activity.events.invoice_deleted_draft')
                : __('activity.events.invoice_deleted_draft_named', ['number' => $invoiceNumber]);
        }

        return $invoiceNumber === null
            ? __('activity.events.invoice_deleted')
            : __('activity.events.invoice_deleted_named', ['number' => $invoiceNumber]);
    }

    private function billingPeriod(array $metadata): ?string
    {
        return match (SubscriptionBillingPeriod::tryFrom((string) ($metadata['billing_period'] ?? ''))) {
            SubscriptionBillingPeriod::Monthly => __('activity.periods.monthly'),
            SubscriptionBillingPeriod::Quarterly => __('activity.periods.quarterly'),
            SubscriptionBillingPeriod::Semiannual => __('activity.periods.semiannual'),
            SubscriptionBillingPeriod::Annual => __('activity.periods.annual'),
            SubscriptionBillingPeriod::Custom => __('activity.periods.custom'),
            null => null,
        };
    }

    private function method(array $metadata): ?string
    {
        return match ($this->text($metadata, 'payment_method')) {
            'transfer' => __('activity.methods.transfer'),
            'card' => __('activity.methods.card'),
            'cash' => __('activity.methods.cash'),
            default => $this->text($metadata, 'payment_method'),
        };
    }

    private function reason(array $metadata): ?string
    {
        $reason = $this->text($metadata, 'reason');

        return $reason === null ? null : __('activity.contexts.reason', ['reason' => $reason]);
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
            'active' => __('activity.statuses.active'),
            'terminated' => __('activity.statuses.terminated'),
            'expired' => __('activity.statuses.expired'),
            'suspended' => __('activity.statuses.suspended'),
            'completed' => __('activity.statuses.completed'),
            'cancelled' => __('activity.statuses.cancelled'),
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
