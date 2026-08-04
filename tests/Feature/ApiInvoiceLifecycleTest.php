<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ApiInvoiceLifecycleTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_api_rejects_direct_status_and_creates_server_calculated_draft(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $payload = $this->payload($contract, $subscription);
        $payload['status'] = 'issued';

        $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        unset($payload['status']);
        $payload['lines'][0]['period_start'] = '2035-01-01';
        $payload['lines'][0]['billing_occurrence_key'] = str_repeat('a', 64);
        $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'lines.0.period_start',
                'lines.0.billing_occurrence_key',
            ]);

        unset($payload['lines'][0]['period_start'], $payload['lines'][0]['billing_occurrence_key']);
        $response = $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('lines.0.period_start', '2026-01-31')
            ->assertJsonPath('lines.0.period_end', '2026-02-27')
            ->assertJsonMissingPath('lines.0.billing_occurrence_key');

        $this->assertSame(64, strlen(Invoice::query()->sole()->lines()->sole()->billing_occurrence_key));
        $this->assertSame('2026-01-31', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_api_rejects_inactive_and_duplicate_occurrences(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $subscription->update(['status' => 'suspended']);
        $this->postJson(route('api.companies.invoices.store', $company), $this->payload($contract, $subscription))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.subscription_id');

        $subscription->update(['status' => 'active']);
        $this->postJson(route('api.companies.invoices.store', $company), $this->payload($contract, $subscription))
            ->assertCreated();
        $duplicate = $this->payload($contract, $subscription);
        $duplicate['invoice_number'] = 'API-DUPLICATE';
        $this->postJson(route('api.companies.invoices.store', $company), $duplicate)
            ->assertUnprocessable();
    }

    public function test_api_can_delete_only_draft(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $this->postJson(route('api.companies.invoices.store', $company), $this->payload($contract, $subscription));
        $draft = Invoice::query()->sole();
        $this->deleteJson(route('api.invoices.destroy', $draft))->assertOk();
        $this->assertDatabaseMissing('invoices', ['id' => $draft->id]);

        foreach (['issued', 'partially_paid', 'paid', 'cancelled'] as $status) {
            $invoice = Invoice::query()->create([
                'company_id' => $company->id,
                'contract_id' => $contract->id,
                'invoice_number' => 'API-DELETE-'.$status,
                'issue_date' => '2026-01-31',
                'due_date' => '2026-02-14',
                'total_amount' => 100,
                'status' => $status,
            ]);
            $this->deleteJson(route('api.invoices.destroy', $invoice))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('invoice');
            $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        }
    }

    public function test_api_update_rejects_line_and_lifecycle_fields_without_side_effects(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $this->postJson(route('api.companies.invoices.store', $company), $this->payload($contract, $subscription))
            ->assertCreated();
        $invoice = Invoice::query()->sole();
        $line = $invoice->lines()->sole();
        $original = $line->only([
            'subscription_id',
            'period_start',
            'period_end',
            'billing_occurrence_key',
        ]);

        $this->patchJson(route('api.invoices.update', $invoice), [
            'comment' => 'Allowed API comment',
            'status' => 'issued',
            'subscription_id' => 999999,
            'period_start' => '2035-01-01',
            'period_end' => '2035-12-31',
            'billing_occurrence_key' => str_repeat('f', 64),
            'lines' => [[
                'id' => $line->id,
                'subscription_id' => 999999,
                'period_start' => '2035-01-01',
                'period_end' => '2035-12-31',
                'billing_occurrence_key' => str_repeat('f', 64),
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'status',
            'subscription_id',
            'period_start',
            'period_end',
            'billing_occurrence_key',
            'lines',
        ]);

        $invoice->refresh();
        $line->refresh();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->comment);
        $this->assertSame($original['subscription_id'], $line->subscription_id);
        $this->assertSame($original['period_start']->toDateString(), $line->period_start->toDateString());
        $this->assertSame($original['period_end']->toDateString(), $line->period_end->toDateString());
        $this->assertSame($original['billing_occurrence_key'], $line->billing_occurrence_key);
        $this->assertSame('2026-01-31', $subscription->fresh()->next_billing_date->toDateString());
    }

    private function subscription(): array
    {
        $company = Company::query()->create([
            'name' => 'API Invoice Lifecycle',
            'status' => 'active',
            'invoice_mode' => 'separate',
        ]);
        $contract = Contract::query()->create([
            'company_id' => $company->id,
            'contract_number' => 'API-INVOICE-CONTRACT',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $subscription = $contract->subscriptions()->forceCreate([
            'title' => 'API Subscription',
            'start_date' => '2026-01-31',
            'next_billing_date' => '2026-01-31',
            'billing_period' => 'monthly',
            'amount' => 100,
            'payment_terms' => 14,
            'status' => 'active',
        ]);

        return [$company, $contract, $subscription];
    }

    private function payload(Contract $contract, Subscription $subscription): array
    {
        return [
            'contract_id' => $contract->id,
            'invoice_number' => 'API-INVOICE-'.$contract->id,
            'issue_date' => '2026-01-31',
            'due_date' => '2026-02-14',
            'total_amount' => 999,
            'lines' => [[
                'subscription_id' => $subscription->id,
                'description' => 'API Subscription',
                'amount' => 100,
            ]],
        ];
    }
}
