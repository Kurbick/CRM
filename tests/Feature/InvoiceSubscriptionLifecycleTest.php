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
        $response = $this->post(route('invoices.store'), $second)
            ->assertSessionHasErrors('lines.0.period_count');

        $this->assertSame(
            ['Этот расчётный период уже зарезервирован другим инвойсом.'],
            $response->getSession()->get('errors')->getBag('default')->all(),
        );

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

    public function test_create_expands_one_logical_monthly_subscription_into_five_occurrences(): void
    {
        [$company, $contract, $subscription] = $this->subscription([
            'start_date' => '2026-06-01',
            'next_billing_date' => '2026-06-01',
            'amount' => '600.00',
        ], 'FIVE');
        $payload = $this->payload($company, $contract, $subscription, [
            'period_count' => 5,
            'expected_period_start' => '2026-06-01',
        ]);
        $payload['invoice_number'] = 'INV-FIVE';
        $payload['lines'][0]['amount'] = '1.00';

        $this->post(route('invoices.store'), $payload)->assertSessionDoesntHaveErrors();

        $invoice = Invoice::query()->where('invoice_number', 'INV-FIVE')->firstOrFail();
        $lines = $invoice->lines()->orderBy('period_start')->get();
        $this->assertCount(5, $lines);
        $this->assertSame(['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'], $lines->map(fn ($line) => $line->period_start->toDateString())->all());
        $this->assertSame(['2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31'], $lines->map(fn ($line) => $line->period_end->toDateString())->all());
        $this->assertSame('3000.00', $invoice->total_amount);
        $this->assertCount(5, $lines->pluck('billing_occurrence_key')->unique());
        $this->assertSame('600.00', $lines->first()->amount);
    }

    public function test_issue_and_cancel_a_multi_period_subscription_advance_and_restore_once(): void
    {
        [$company, $contract, $subscription] = $this->subscription([
            'start_date' => '2026-06-01',
            'next_billing_date' => '2026-06-01',
        ], 'MULTI-LIFECYCLE');
        $payload = $this->payload($company, $contract, $subscription, ['period_count' => 5]);
        $payload['invoice_number'] = 'INV-MULTI-LIFECYCLE';
        $this->post(route('invoices.store'), $payload)->assertSessionDoesntHaveErrors();
        $invoice = Invoice::query()->where('invoice_number', 'INV-MULTI-LIFECYCLE')->firstOrFail();

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();
        $this->assertSame('2026-11-01', $subscription->fresh()->next_billing_date->toDateString());

        $this->patch(route('invoices.cancel', $invoice))->assertSessionDoesntHaveErrors();
        $this->assertSame('2026-06-01', $subscription->fresh()->next_billing_date->toDateString());
        $this->assertSame(5, $invoice->lines()->whereNotNull('subscription_id')->whereNull('billing_occurrence_key')->count());
    }

    public function test_stale_expected_period_start_and_contract_boundary_reject_the_entire_create(): void
    {
        [$company, $contract, $subscription] = $this->subscription([
            'start_date' => '2026-06-01',
            'next_billing_date' => '2026-06-01',
            'amount' => '500.00',
        ], 'STALE');
        $firstTabPayload = $this->payload($company, $contract, $subscription, [
            'period_count' => 2,
            'expected_period_start' => '2026-06-01',
        ]);
        $firstTabPayload['invoice_number'] = 'INV-STALE-FIRST';
        $secondTabPayload = $this->payload($company, $contract, $subscription, [
            'period_count' => 2,
            'expected_period_start' => '2026-06-01',
        ]);
        $secondTabPayload['invoice_number'] = 'INV-STALE-SECOND';
        unset($secondTabPayload['lines'][0]['amount']);

        $this->post(route('invoices.store'), $firstTabPayload)->assertSessionDoesntHaveErrors();
        $firstInvoice = Invoice::query()->where('invoice_number', 'INV-STALE-FIRST')->firstOrFail();
        $this->post(route('invoices.issue', $firstInvoice))->assertSessionDoesntHaveErrors();

        $response = $this->from(route('invoices.create', ['company_id' => $company->id, 'contract_id' => $contract->id]))
            ->post(route('invoices.store'), $secondTabPayload);
        $response->assertRedirect(route('invoices.create', ['company_id' => $company->id, 'contract_id' => $contract->id]))
            ->assertSessionHasErrors('lines.0.period_count')
            ->assertSessionHasInput('lines.0.period_count', 2)
            ->assertSessionHasInput('lines.0.subscription_id', $subscription->id);
        $this->assertSame(
            ['Расчётный период подписки изменился. Обновите данные и выберите периоды заново.'],
            $response->getSession()->get('errors')->getBag('default')->all(),
        );
        $this->assertDatabaseCount('invoices', 1);

        $page = $this->followingRedirects()
            ->from(route('invoices.create', ['company_id' => $company->id, 'contract_id' => $contract->id]))
            ->post(route('invoices.store'), $secondTabPayload)
            ->assertOk()
            ->assertSee('Расчётный период подписки изменился. Обновите данные и выберите периоды заново.')
            ->assertSee('\u0022period_count\u0022:2', false)
            ->assertSee('500.00', false)
            ->assertSee('2026-07-31', false)
            ->assertDontSee('billing occurrence', false)
            ->assertDontSee('Этот расчётный период уже зарезервирован другим инвойсом.', false)
            ->assertDontSee('По этой подписке уже существует инвойс за выбранный расчётный период.', false);
        $this->assertSame(1, substr_count($page->getContent(), 'Расчётный период подписки изменился. Обновите данные и выберите периоды заново.'));

        $contract->update(['end_date' => '2026-08-31']);
        $secondTabPayload['lines'][0]['expected_period_start'] = '2026-08-01';
        $secondTabPayload['lines'][0]['period_count'] = 2;
        $this->post(route('invoices.store'), $secondTabPayload)->assertSessionHasErrors('lines.0.period_count');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_web_period_count_is_bounded_and_orders_cannot_be_multiplied(): void
    {
        [$company, $contract, $subscription] = $this->subscription([], 'COUNT-BOUNDARY');
        foreach ([0, 25, '1.5'] as $count) {
            $payload = $this->payload($company, $contract, $subscription, ['period_count' => $count]);
            $payload['invoice_number'] = 'INV-COUNT-'.str_replace('.', '-', (string) $count);
            $this->post(route('invoices.store'), $payload)->assertSessionHasErrors('lines.0.period_count');
        }
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_draft_subscription_group_resizes_by_trailing_occurrences_and_keeps_its_amount_snapshot(): void
    {
        [$company, $contract, $subscription] = $this->subscription([
            'start_date' => '2026-06-01',
            'next_billing_date' => '2026-06-01',
            'amount' => '600.00',
        ], 'RESIZE');
        $payload = $this->payload($company, $contract, $subscription, ['period_count' => 5]);
        $payload['invoice_number'] = 'INV-RESIZE';
        $this->post(route('invoices.store'), $payload)->assertSessionDoesntHaveErrors();
        $invoice = Invoice::query()->where('invoice_number', 'INV-RESIZE')->firstOrFail();
        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('\u0022period_count\u0022:5', false)
            ->assertSee('x-bind:value="periodCount(line)"', false);
        $subscription->update(['amount' => '700.00']);

        $this->put(route('invoices.update', $invoice), $this->updatePayload($invoice, $subscription->id, 3))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(3, $invoice->fresh()->lines()->count());
        $this->assertSame('1800.00', $invoice->fresh()->total_amount);
        $this->assertSame(['600.00'], $invoice->fresh()->lines()->pluck('amount')->unique()->all());
        $this->get(route('invoices.edit', $invoice->fresh()))
            ->assertOk()
            ->assertSee('\u0022period_count\u0022:3', false);

        $this->put(route('invoices.update', $invoice->fresh()), $this->updatePayload($invoice->fresh(), $subscription->id, 2))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(2, $invoice->fresh()->lines()->count());
        $this->assertSame(['2026-06-01', '2026-07-01'], $invoice->fresh()->lines()->orderBy('period_start')->get()->map(fn ($line) => $line->period_start->toDateString())->all());
    }

    public function test_draft_edit_period_count_uses_old_resize_value_before_persisted_group_count(): void
    {
        [$company, $contract, $subscription] = $this->subscription([], 'EDIT-OLD-COUNT');
        $payload = $this->payload($company, $contract, $subscription, ['period_count' => 3]);
        $payload['invoice_number'] = 'INV-EDIT-OLD-COUNT';
        $this->post(route('invoices.store'), $payload)->assertSessionDoesntHaveErrors();
        $invoice = Invoice::query()->where('invoice_number', 'INV-EDIT-OLD-COUNT')->firstOrFail();
        $contract->update(['end_date' => '2026-04-29']);

        $response = $this->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), $this->updatePayload($invoice, $subscription->id, 4));
        $response->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors('subscription_period_counts');

        $this->followingRedirects()
            ->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), $this->updatePayload($invoice, $subscription->id, 4))
            ->assertOk()
            ->assertSee('\u0022period_count\u0022:4', false);
    }

    public function test_single_occurrence_draft_edit_renders_period_count_one(): void
    {
        [$company, $contract, $subscription] = $this->subscription([], 'EDIT-SINGLE-COUNT');
        $payload = $this->payload($company, $contract, $subscription);
        $payload['invoice_number'] = 'INV-EDIT-SINGLE-COUNT';
        $this->post(route('invoices.store'), $payload)->assertSessionDoesntHaveErrors();
        $invoice = Invoice::query()->where('invoice_number', 'INV-EDIT-SINGLE-COUNT')->firstOrFail();

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('\u0022period_count\u0022:1', false);
    }

    private function subscription(array $overrides = [], string $suffix = 'MAIN'): array
    {
        $company = Company::query()->create([
            'name' => 'Lifecycle '.$suffix,
            'status' => 'active',
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

    private function updatePayload(Invoice $invoice, int $subscriptionId, int $periodCount): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => substr((string) $invoice->issue_date, 0, 10),
            'due_date' => substr((string) $invoice->due_date, 0, 10),
            'lines' => $invoice->lines()->orderBy('id')->get()->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'amount' => $line->amount,
                'subscription_id' => $line->subscription_id,
                'order_id' => $line->order_id,
                'period_start' => $line->period_start?->toDateString(),
                'period_end' => $line->period_end?->toDateString(),
            ])->all(),
            'subscription_period_counts' => [[
                'subscription_id' => $subscriptionId,
                'period_count' => $periodCount,
            ]],
        ];
    }
}
