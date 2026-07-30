<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\SubscriptionBillingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InvoiceSubscriptionLifecycleTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_draft_reserves_server_calculated_occurrence_without_advancing(): void
    {
        [$company, $contract, $subscription] = $this->subscription();

        $this->post(route('invoices.store'), $this->payload($company, $contract, $subscription, [
            'period_start' => '2035-01-01',
            'period_end' => '2035-12-31',
        ]))->assertSessionDoesntHaveErrors();

        $line = Invoice::query()->sole()->lines()->sole();
        $this->assertSame('2026-01-31', $line->period_start->toDateString());
        $this->assertSame('2026-02-27', $line->period_end->toDateString());
        $this->assertSame(64, strlen($line->billing_occurrence_key));
        $this->assertSame('2026-01-31', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_duplicate_draft_occurrence_is_rejected(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $this->post(route('invoices.store'), $this->payload($company, $contract, $subscription));

        $second = $this->payload($company, $contract, $subscription);
        $second['invoice_number'] = 'INV-DUPLICATE';
        $this->post(route('invoices.store'), $second)->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_issue_advances_once_and_preserves_month_end_anchor(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $this->post(route('invoices.store'), $this->payload($company, $contract, $subscription));
        $invoice = Invoice::query()->sole();

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();
        $this->assertSame('2026-02-28', $subscription->fresh()->next_billing_date->toDateString());

        $this->post(route('invoices.issue', $invoice))->assertSessionHasErrors('issue');
        $this->assertSame('2026-02-28', $subscription->fresh()->next_billing_date->toDateString());

        $next = $this->payload($company, $contract, $subscription);
        $next['invoice_number'] = 'INV-NEXT';
        $this->post(route('invoices.store'), $next)->assertSessionDoesntHaveErrors();
        $nextLine = Invoice::query()->where('invoice_number', 'INV-NEXT')->firstOrFail()->lines()->sole();
        $this->assertSame('2026-03-30', $nextLine->period_end->toDateString());
    }

    public function test_custom_day_month_and_year_intervals_advance_on_issue(): void
    {
        $cases = [
            ['CUSTOM-DAY', '2026-01-01', 45, 'day', '2026-02-15'],
            ['CUSTOM-MONTH', '2026-01-31', 2, 'month', '2026-03-31'],
            ['CUSTOM-YEAR', '2024-02-29', 1, 'year', '2025-02-28'],
        ];

        foreach ($cases as [$number, $start, $value, $unit, $expected]) {
            [$company, $contract, $subscription] = $this->subscription([
                'start_date' => $start,
                'next_billing_date' => $start,
                'billing_period' => 'custom',
                'custom_interval_value' => $value,
                'custom_interval_unit' => $unit,
            ], $number);
            $payload = $this->payload($company, $contract, $subscription);
            $payload['invoice_number'] = $number;

            $this->post(route('invoices.store'), $payload)->assertSessionDoesntHaveErrors();
            $invoice = Invoice::query()->where('invoice_number', $number)->firstOrFail();
            $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

            $this->assertSame($expected, $subscription->fresh()->next_billing_date->toDateString());
        }
    }

    public function test_cancellation_clears_reservation_and_allows_same_occurrence_again(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $this->post(route('invoices.store'), $this->payload($company, $contract, $subscription));
        $invoice = Invoice::query()->sole();
        $this->post(route('invoices.issue', $invoice));

        $this->patch(route('invoices.cancel', $invoice))->assertSessionDoesntHaveErrors();
        $this->assertSame('2026-01-31', $subscription->fresh()->next_billing_date->toDateString());
        $this->assertNull($invoice->lines()->sole()->billing_occurrence_key);

        $replacement = $this->payload($company, $contract, $subscription);
        $replacement['invoice_number'] = 'INV-REPLACEMENT';
        $this->post(route('invoices.store'), $replacement)->assertSessionDoesntHaveErrors();
        $this->assertNotNull(
            Invoice::query()->where('invoice_number', 'INV-REPLACEMENT')->firstOrFail()->lines()->sole()->billing_occurrence_key
        );
    }

    public function test_cancellation_failure_rolls_back_invoice_key_and_schedule(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $this->post(route('invoices.store'), $this->payload($company, $contract, $subscription));
        $invoice = Invoice::query()->sole();
        $this->post(route('invoices.issue', $invoice));
        $key = $invoice->lines()->sole()->billing_occurrence_key;

        Subscription::updating(function (Subscription $updating) use ($subscription): void {
            if ($updating->is($subscription)) {
                throw new \RuntimeException('Rollback marker');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->patch(route('invoices.cancel', $invoice));
            $this->fail('Cancellation should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback marker', $exception->getMessage());
        } finally {
            Subscription::flushEventListeners();
        }

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame($key, $invoice->lines()->sole()->billing_occurrence_key);
        $this->assertSame('2026-02-28', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_issue_assigns_legacy_null_occurrence_key_and_does_not_advance_twice(): void
    {
        [$company, $contract, $subscription] = $this->subscription();
        $this->post(route('invoices.store'), $this->payload($company, $contract, $subscription));
        $invoice = Invoice::query()->sole();
        $line = $invoice->lines()->sole();
        $line->billing_occurrence_key = null;
        $line->save();

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();
        $line->refresh();
        $this->assertSame(
            app(SubscriptionBillingSchedule::class)->occurrenceKey(
                $subscription->id,
                CarbonImmutable::parse($line->period_start),
                CarbonImmutable::parse($line->period_end),
            ),
            $line->billing_occurrence_key,
        );
        $this->assertSame('2026-02-28', $subscription->fresh()->next_billing_date->toDateString());

        $this->post(route('invoices.issue', $invoice))->assertSessionHasErrors('issue');
        $this->assertSame('2026-02-28', $subscription->fresh()->next_billing_date->toDateString());
    }

    private function subscription(array $overrides = [], string $suffix = 'MAIN'): array
    {
        $company = Company::query()->create([
            'name' => 'Lifecycle '.$suffix,
            'status' => 'active',
            'invoice_mode' => 'separate',
        ]);
        $contract = Contract::query()->create([
            'company_id' => $company->id,
            'contract_number' => 'CONTRACT-'.$suffix,
            'start_date' => '2024-01-01',
            'status' => 'active',
        ]);
        $subscription = $contract->subscriptions()->forceCreate([
            'title' => 'Subscription '.$suffix,
            'start_date' => '2026-01-31',
            'next_billing_date' => '2026-01-31',
            'billing_period' => 'monthly',
            'amount' => 100,
            'payment_terms' => 14,
            'status' => 'active',
            ...$overrides,
        ]);

        return [$company, $contract, $subscription];
    }

    private function payload(Company $company, Contract $contract, Subscription $subscription, array $line = []): array
    {
        return [
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-'.$company->id,
            'issue_date' => '2026-01-31',
            'due_date' => '2026-02-14',
            'lines' => [[
                'subscription_id' => $subscription->id,
                'description' => $subscription->title,
                'amount' => 100,
                ...$line,
            ]],
        ];
    }
}
