<?php

namespace Tests\Feature;

use App\Actions\Invoices\CreateInvoice;
use App\Actions\Invoices\DeleteInvoice;
use App\Actions\Invoices\UpdateInvoice;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceNumberCounter;
use App\Models\Organization;
use App\Models\Order;
use App\Models\ServiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FinancialTestCase as TestCase;

class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_does_not_reserve_and_saved_drafts_advance_the_counter(): void
    {
        $organization = $this->numberingOrganization();
        $this->assertSame([], InvoiceNumberCounter::query()->get()->all());

        $preview = app(\App\Services\InvoiceNumberService::class)->preview($organization, 2026);
        $this->assertSame(1, $preview['sequence']);
        $this->assertSame([], InvoiceNumberCounter::query()->get()->all());

        $first = $this->createDraft('2026-08-01');
        $second = $this->createDraft('2026-08-02');

        $this->assertSame('1/ZL-26', $first->invoice_number);
        $this->assertSame('2/ZL-26', $second->invoice_number);
        $this->assertDatabaseHas('invoice_number_counters', [
            'organization_id' => $organization->id,
            'year' => 2026,
            'last_sequence' => 2,
        ]);
    }

    public function test_year_is_taken_from_issue_date_and_manual_numbers_do_not_lower_high_water(): void
    {
        $this->numberingOrganization();
        $this->createDraft('2026-12-31');
        $nextYear = $this->createDraft('2027-01-01');

        $this->assertSame('1/ZL-27', $nextYear->invoice_number);

        $manual = $this->createDraft('2026-08-03', 2, true);
        $this->assertSame('2/ZL-26', $manual->invoice_number);
        $this->assertSame(2, InvoiceNumberCounter::query()->where('year', 2026)->value('last_sequence'));
    }

    public function test_deleted_numbers_are_not_reused_automatically_but_can_be_reused_manually(): void
    {
        $this->numberingOrganization();
        $first = $this->createDraft('2026-08-01');
        $second = $this->createDraft('2026-08-02');

        app(DeleteInvoice::class)->execute($first);
        $third = $this->createDraft('2026-08-03');

        $this->assertSame('2/ZL-26', $second->invoice_number);
        $this->assertSame('3/ZL-26', $third->invoice_number);

        $reused = $this->createDraft('2026-08-04', 1, true);
        $this->assertSame('1/ZL-26', $reused->invoice_number);
        $this->assertSame(3, InvoiceNumberCounter::query()->where('year', 2026)->value('last_sequence'));
    }

    public function test_manual_duplicate_is_rejected_and_higher_manual_number_advances_counter(): void
    {
        $this->numberingOrganization();
        $this->createDraft('2026-08-01', 10, true);

        $this->expectException(ValidationException::class);
        $this->createDraft('2026-08-02', 10, true);
    }

    public function test_manual_higher_number_is_followed_by_next_automatic_number(): void
    {
        $this->numberingOrganization();
        $manual = $this->createDraft('2026-08-01', 150, true);
        $automatic = $this->createDraft('2026-08-02');

        $this->assertSame('150/ZL-26', $manual->invoice_number);
        $this->assertSame('151/ZL-26', $automatic->invoice_number);
    }

    public function test_organization_code_is_snapshotted_on_the_invoice(): void
    {
        $organization = $this->numberingOrganization();
        $invoice = $this->createDraft('2026-08-01');

        $organization->update(['invoice_number_code' => 'ABC']);

        $this->assertSame('1/ZL-26', $invoice->fresh()->invoice_number);
        $this->assertSame('ZL', $invoice->fresh()->invoice_number_code);
    }

    public function test_editing_number_and_moving_year_preserves_snapshot_and_high_water_rules(): void
    {
        $this->numberingOrganization();
        $invoice = $this->createDraft('2026-08-01');
        $other = $this->createDraft('2026-08-02');
        $moved = $this->createDraft('2026-08-03');
        app(DeleteInvoice::class)->execute($invoice);

        app(UpdateInvoice::class)->execute($other, [
            'issue_date' => '2026-08-03',
            'invoice_number_sequence' => 1,
            'invoice_number_manual' => true,
        ]);

        $this->assertSame('1/ZL-26', $other->fresh()->invoice_number);
        $this->assertSame(3, InvoiceNumberCounter::query()->where('year', 2026)->value('last_sequence'));

        app(UpdateInvoice::class)->execute($moved, [
            'issue_date' => '2027-01-02',
            'due_date' => '2027-02-01',
            'invoice_number_sequence' => 1,
            'invoice_number_manual' => false,
        ]);

        $moved = $moved->fresh();
        $this->assertSame('1/ZL-27', $moved->invoice_number);
        $this->assertSame(2027, $moved->invoice_number_year);
        $this->assertSame('ZL', $moved->invoice_number_code);
    }

    public function test_legacy_invoice_number_is_preserved_by_ordinary_update(): void
    {
        $this->numberingOrganization();
        $company = $this->company();
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $this->contract($company)->id,
            'invoice_number' => 'LEGACY-001',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '10.00',
            'status' => 'draft',
        ]);

        app(UpdateInvoice::class)->execute($invoice, ['comment' => 'Updated']);

        $this->assertSame('LEGACY-001', $invoice->fresh()->invoice_number);
        $this->assertNull($invoice->fresh()->invoice_number_sequence);
    }

    public function test_missing_numbering_code_returns_validation_error(): void
    {
        $this->numberingOrganization(null);

        $this->expectException(ValidationException::class);
        $this->createDraft('2026-08-01');
    }

    public function test_duplicate_manual_sequence_is_rendered_once_in_each_locale(): void
    {
        $this->numberingOrganization();
        $this->createDraft('2026-08-01', 7, true);
        $company = $this->company();
        $contract = $this->contract($company);
        $order = $this->order($contract);

        foreach ([
            'ru' => 'Этот номер инвойса уже используется для выбранного года.',
            'az' => 'Bu invoys nömrəsi seçilmiş il üçün artıq istifadə olunur.',
        ] as $locale => $message) {
            $this->withSession(['locale' => $locale])
                ->from(route('invoices.create'))
                ->post(route('invoices.store'), [
                    'company_id' => $company->id,
                    'contract_id' => $contract->id,
                    'invoice_number_sequence' => 7,
                    'invoice_number_manual' => 1,
                    'issue_date' => '2026-08-02',
                    'due_date' => '2026-09-01',
                    'lines' => [[
                        'description' => 'Duplicate number test',
                        'amount' => '10.00',
                        'order_id' => $order->id,
                    ]],
                ])
                ->assertRedirect(route('invoices.create'));

            $response = $this->get(route('invoices.create'));
            $response->assertSeeText($message)
                ->assertDontSee('invoices.errors.number_sequence_taken');
            $this->assertSame(1, substr_count($response->getContent(), $message));
        }
    }

    private function numberingOrganization(?string $code = 'ZL'): Organization
    {
        $organization = Organization::query()->firstOrFail();
        $organization->update(['invoice_number_code' => $code]);

        return $organization->fresh();
    }

    private function createDraft(string $issueDate, ?int $sequence = null, bool $manual = false): Invoice
    {
        $company = $this->company();
        $contract = $this->contract($company);
        $attributes = [
            'issue_date' => $issueDate,
            'due_date' => date('Y-m-d', strtotime($issueDate.' +30 days')),
        ];
        if ($sequence !== null) {
            $attributes['invoice_number_sequence'] = $sequence;
            $attributes['invoice_number_manual'] = $manual;
        }

        return app(CreateInvoice::class)->execute(
            $company,
            $contract,
            $attributes,
            [['description' => 'Test service', 'amount' => '10.00']],
        );
    }

    private function company(): Company
    {
        return Company::query()->create(['name' => 'Numbering company '.uniqid(), 'status' => 'active']);
    }

    private function contract(Company $company): Contract
    {
        return Contract::query()->create([
            'company_id' => $company->id,
            'contract_number' => 'NUM-'.uniqid(),
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    private function order(Contract $contract): Order
    {
        $serviceType = ServiceType::query()->create([
            'name' => 'Numbering one-time service '.uniqid(),
            'base_price' => '10.00',
            'type' => 'one_time',
        ]);

        return $contract->orders()->create([
            'service_type_id' => $serviceType->id,
            'title' => 'Duplicate number service',
            'order_date' => '2026-08-01',
            'price' => '10.00',
            'payment_terms' => 30,
            'status' => 'in_progress',
        ]);
    }
}
