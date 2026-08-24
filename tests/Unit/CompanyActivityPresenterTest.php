<?php

namespace Tests\Unit;

use App\Models\CompanyActivityEvent;
use App\Models\User;
use App\Services\CompanyActivityPresenter;
use App\Support\CompanyActivityEventType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyActivityPresenterTest extends TestCase
{
    public function test_confirmed_payment_has_exact_title_and_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::PaymentConfirmed, [
            'amount_minor' => 60000,
            'currency' => '₼',
            'invoice_number' => 'INV-254D47',
            'payment_method' => 'transfer',
        ]);

        $this->assertSame('Платёж 600,00 ₼ подтверждён', $presentation['title']);
        $this->assertSame('INV-254D47 · Безналичный', $presentation['context']);
    }

    public function test_pending_payment_includes_amount_without_duplicating_it_in_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::PaymentPendingCreated, [
            'amount_minor' => 60000,
            'currency' => '₼',
            'invoice_number' => 'INV-254D47',
        ]);

        $this->assertSame('Платёж 600,00 ₼ ожидает подтверждения', $presentation['title']);
        $this->assertSame('INV-254D47', $presentation['context']);
    }

    public function test_issued_invoice_uses_invoice_number_in_title_and_contract_in_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::InvoiceIssued, [
            'invoice_number' => 'INV-254D47',
            'contract_number' => 'CTR-2026-001',
            'amount_minor' => 120000,
            'currency' => '₼',
        ]);

        $this->assertSame('Инвойс INV-254D47 выставлен', $presentation['title']);
        $this->assertSame('CTR-2026-001 · 1 200,00 ₼', $presentation['context']);
    }

    public function test_issued_invoice_falls_back_without_inventing_contract_number(): void
    {
        $presentation = $this->present(CompanyActivityEventType::InvoiceIssued, [
            'invoice_number' => 'INV-254D47',
            'amount_minor' => 120000,
        ]);

        $this->assertSame('Инвойс INV-254D47 выставлен', $presentation['title']);
        $this->assertSame('1 200,00 ₼', $presentation['context']);
    }

    public function test_cancelled_payment_prefers_reason_over_invoice_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::PaymentCancelled, [
            'amount_minor' => 120000,
            'invoice_number' => 'INV-254D47',
            'reason' => 'Просто так',
        ]);

        $this->assertSame('Платёж 1 200,00 ₼ отменён', $presentation['title']);
        $this->assertSame('Причина: Просто так', $presentation['context']);
    }

    public function test_uploaded_document_uses_filename_in_title_and_contract_in_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::DocumentUploaded, [
            'document_name' => 'instrukciya-dlya-zhurnalistov.pdf',
            'contract_number' => 'XL-012005',
        ]);

        $this->assertSame('Загружен документ instrukciya-dlya-zhurnalistov.pdf', $presentation['title']);
        $this->assertSame('Договор XL-012005', $presentation['context']);
        $this->assertNull($presentation['subject_url']);
    }

    public function test_uploaded_document_falls_back_without_fake_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::DocumentUploaded, []);

        $this->assertSame('Документ загружен', $presentation['title']);
        $this->assertNull($presentation['context']);
    }

    public function test_contract_created_uses_number_and_date_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::ContractCreated, [
            'contract_number' => 'CTR-2026-001',
            'start_date' => '2026-08-01',
            'end_date' => '2027-11-01',
        ]);

        $this->assertSame('Создан договор CTR-2026-001', $presentation['title']);
        $this->assertSame('01/08/2026 — 01/11/2027', $presentation['context']);
    }

    public function test_contract_status_change_uses_number_and_status_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::ContractStatusChanged, [
            'contract_number' => 'CTR-2026-001',
            'old_status' => 'active',
            'new_status' => 'terminated',
        ]);

        $this->assertSame('Статус договора CTR-2026-001 изменён', $presentation['title']);
        $this->assertSame('Активен → Завершён', $presentation['context']);
    }

    public function test_deleted_contract_uses_snapshot_without_a_broken_link(): void
    {
        $presentation = $this->present(CompanyActivityEventType::ContractDeleted, [
            'contract_number' => 'CTR-2026-003',
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->assertSame('Удалён договор CTR-2026-003', $presentation['title']);
        $this->assertSame('01/08/2026 — 01/09/2026', $presentation['context']);
        $this->assertSame('contract-deleted', $presentation['icon']);
        $this->assertSame('red', $presentation['tone']);
        $this->assertNull($presentation['subject_url']);
    }

    public function test_subscription_subject_uses_canonical_wording_and_monthly_period(): void
    {
        $presentation = $this->present(CompanyActivityEventType::ContractSubjectCreated, [
            'subject_type' => 'subscription',
            'subject_name' => 'Support',
            'contract_number' => 'CTR-2026-001',
            'amount_minor' => 60000,
            'billing_period' => 'monthly',
        ]);

        $this->assertSame('Добавлена подписка Support', $presentation['title']);
        $this->assertSame('Договор CTR-2026-001 · 600,00 ₼ / ежемесячно', $presentation['context']);
    }

    public function test_one_time_subject_uses_one_time_wording_without_billing_period(): void
    {
        $presentation = $this->present(CompanyActivityEventType::ContractSubjectCreated, [
            'subject_type' => 'one_time',
            'subject_name' => 'Audit',
            'contract_number' => 'CTR-2026-001',
            'amount_minor' => 60000,
            'billing_period' => 'monthly',
        ]);

        $this->assertSame('Добавлена разовая услуга Audit', $presentation['title']);
        $this->assertSame('Договор CTR-2026-001 · 600,00 ₼', $presentation['context']);
    }

    public function test_subject_update_and_delete_use_type_specific_wording_and_snapshot_context(): void
    {
        $updated = $this->present(CompanyActivityEventType::ContractSubjectUpdated, [
            'subject_type' => 'subscription',
            'subject_name' => 'Support',
            'contract_number' => 'CTR-2026-001',
            'amount_minor' => 60000,
            'billing_period' => 'monthly',
        ]);
        $deleted = $this->present(CompanyActivityEventType::ContractSubjectDeleted, [
            'subject_type' => 'one_time',
            'subject_name' => 'Разработка сайта',
            'contract_number' => 'CTR-2026-001',
            'amount_minor' => 120000,
        ]);

        $this->assertSame('Подписка Support изменена', $updated['title']);
        $this->assertSame('Договор CTR-2026-001 · 600,00 ₼ / ежемесячно', $updated['context']);
        $this->assertSame('Разовая услуга Разработка сайта удалена', $deleted['title']);
        $this->assertSame('Договор CTR-2026-001 · 1 200,00 ₼', $deleted['context']);
        $this->assertSame('subject-updated', $updated['icon']);
        $this->assertSame('subject-deleted', $deleted['icon']);
    }

    public function test_deleted_document_uses_filename_and_contract_context(): void
    {
        $presentation = $this->present(CompanyActivityEventType::DocumentDeleted, [
            'document_name' => 'contract.pdf',
            'contract_number' => 'CTR-2026-001',
        ]);

        $this->assertSame('Удалён документ contract.pdf', $presentation['title']);
        $this->assertSame('Договор CTR-2026-001', $presentation['context']);
        $this->assertSame('document-deleted', $presentation['icon']);
        $this->assertSame('red', $presentation['tone']);
    }

    #[DataProvider('billingPeriodProvider')]
    public function test_billing_periods_have_canonical_russian_presentation(string $period, string $label): void
    {
        $presentation = $this->present(CompanyActivityEventType::ContractSubjectCreated, [
            'subject_type' => 'subscription',
            'subject_name' => 'Support',
            'amount_minor' => 60000,
            'billing_period' => $period,
        ]);

        $this->assertSame('600,00 ₼ / '.$label, $presentation['context']);
    }

    /** @return array<string, array{string, string}> */
    public static function billingPeriodProvider(): array
    {
        return [
            'monthly' => ['monthly', 'ежемесячно'],
            'quarterly' => ['quarterly', 'ежеквартально'],
            'semiannual' => ['semiannual', 'раз в полгода'],
            'annual' => ['annual', 'ежегодно'],
            'custom' => ['custom', 'индивидуальный период'],
        ];
    }

    public function test_missing_optional_metadata_has_clean_fallbacks(): void
    {
        $pending = $this->present(CompanyActivityEventType::PaymentPendingCreated, []);
        $subject = $this->present(CompanyActivityEventType::ContractSubjectCreated, []);
        $invoice = $this->present(CompanyActivityEventType::InvoiceIssued, []);

        $this->assertSame('Платёж ожидает подтверждения', $pending['title']);
        $this->assertNull($pending['context']);
        $this->assertSame('Добавлен предмет договора', $subject['title']);
        $this->assertNull($subject['context']);
        $this->assertSame('Инвойс выставлен', $invoice['title']);
        $this->assertNull($invoice['context']);
    }

    public function test_money_formatting_uses_minor_units_without_float_rounding(): void
    {
        $presentation = $this->present(CompanyActivityEventType::PaymentConfirmed, [
            'amount_minor' => 120000,
            'currency' => '₼',
        ]);

        $this->assertSame('Платёж 1 200,00 ₼ подтверждён', $presentation['title']);
    }

    public function test_metadata_contract_is_structured_and_does_not_require_localized_prose(): void
    {
        $metadata = [
            'subject_type' => 'subscription',
            'subject_name' => 'Support',
            'contract_number' => 'CTR-2026-001',
            'amount_minor' => 60000,
            'billing_period' => 'monthly',
        ];
        $presentation = $this->present(CompanyActivityEventType::ContractSubjectCreated, $metadata);

        $this->assertArrayNotHasKey('title', $metadata);
        $this->assertArrayNotHasKey('context', $metadata);
        $this->assertSame('Добавлена подписка Support', $presentation['title']);
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function present(CompanyActivityEventType $type, array $metadata): array
    {
        $event = new CompanyActivityEvent([
            'event_type' => $type->value,
            'metadata' => $metadata,
            'occurred_at' => CarbonImmutable::parse('2026-08-24 06:42:00', 'UTC'),
        ]);

        return (new CompanyActivityPresenter)->present($event, new User(['name' => 'Kurban']));
    }
}
