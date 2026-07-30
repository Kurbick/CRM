<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\ServiceType;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ApiSubscriptionLifecycleTest extends AuthorizationTestCase
{
    public function test_api_rejects_client_next_date_and_calculates_it_on_create(): void
    {
        $this->actingAsPermissions();
        $contract = $this->contract($this->company());
        $serviceType = $this->serviceType();

        $this->postJson(route('api.contracts.subscriptions.store', $contract), [
            ...$this->payload($serviceType->id),
            'next_billing_date' => '2035-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('next_billing_date');
        $this->assertDatabaseCount('subscriptions', 0);

        $this->postJson(route('api.contracts.subscriptions.store', $contract), $this->payload($serviceType->id))
            ->assertCreated();
        $this->assertSame('2026-01-31', Subscription::query()->sole()->next_billing_date->toDateString());
    }

    public function test_api_custom_validation_and_ordinary_update_preserve_schedule(): void
    {
        $this->actingAsPermissions();
        $contract = $this->contract($this->company());
        $serviceType = $this->serviceType();

        $this->postJson(route('api.contracts.subscriptions.store', $contract), [
            ...$this->payload($serviceType->id),
            'billing_period' => 'custom',
        ])->assertJsonValidationErrors(['custom_interval_value', 'custom_interval_unit']);

        $subscription = $this->subjectSubscription($contract, [
            'next_billing_date' => '2027-03-31',
        ]);
        $this->putJson(route('api.subscriptions.update', $subscription), [
            'amount' => 250,
            'payment_terms' => 7,
            'comment' => 'API changed',
        ])->assertOk();

        $this->assertSame('2027-03-31', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_api_reactivation_and_history_schedule_guard_match_web(): void
    {
        CarbonImmutable::setTestNow('2026-07-30');

        try {
            $this->actingAsPermissions();
            $contract = $this->contract($this->company());
            $subscription = $this->subjectSubscription($contract, [
                'status' => 'suspended',
                'next_billing_date' => '2026-01-01',
            ]);

            $this->putJson(route('api.subscriptions.update', $subscription), [
                'status' => 'active',
                'payment_terms' => 14,
            ])->assertOk();
            $this->assertSame('2026-07-30', $subscription->fresh()->next_billing_date->toDateString());

            $invoice = Invoice::query()->create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'invoice_number' => 'API-HISTORY',
                'issue_date' => '2026-07-30',
                'due_date' => '2026-08-13',
                'total_amount' => 100,
                'status' => 'cancelled',
            ]);
            $invoice->lines()->create([
                'subscription_id' => $subscription->id,
                'description' => 'History',
                'amount' => 100,
                'period_start' => '2026-07-30',
                'period_end' => '2026-08-29',
            ]);

            $this->putJson(route('api.subscriptions.update', $subscription), [
                'start_date' => '2026-09-01',
                'payment_terms' => 14,
            ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_api_partial_period_and_start_updates_use_effective_schedule_state(): void
    {
        $this->actingAsPermissions();
        $contract = $this->contract($this->company());
        $subscription = $this->subjectSubscription($contract, [
            'start_date' => '2026-08-01',
            'next_billing_date' => '2027-01-01',
            'billing_period' => 'monthly',
        ]);

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'billing_period' => 'quarterly',
            'payment_terms' => 14,
        ])->assertOk();
        $subscription->refresh();
        $this->assertSame('quarterly', $subscription->billing_period);
        $this->assertSame('2026-08-01', $subscription->start_date->toDateString());
        $this->assertSame('2026-08-01', $subscription->next_billing_date->toDateString());

        $subscription->next_billing_date = '2027-02-01';
        $subscription->save();
        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'start_date' => '2026-09-15',
            'payment_terms' => 14,
        ])->assertOk();
        $subscription->refresh();
        $this->assertSame('quarterly', $subscription->billing_period);
        $this->assertSame('2026-09-15', $subscription->start_date->toDateString());
        $this->assertSame('2026-09-15', $subscription->next_billing_date->toDateString());
    }

    public function test_api_custom_updates_require_a_pair_but_ordinary_update_reuses_saved_pair(): void
    {
        $this->actingAsPermissions();
        $subscription = $this->subjectSubscription($this->contract($this->company()), [
            'billing_period' => 'custom',
            'custom_interval_value' => 2,
            'custom_interval_unit' => 'month',
            'next_billing_date' => '2027-01-01',
        ]);

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'custom_interval_value' => 3,
            'payment_terms' => 14,
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_interval_unit');
        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'custom_interval_unit' => 'year',
            'payment_terms' => 14,
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_interval_value');
        $this->assertSame(2, $subscription->fresh()->custom_interval_value);
        $this->assertSame('month', $subscription->fresh()->custom_interval_unit);
        $this->assertSame('2027-01-01', $subscription->fresh()->next_billing_date->toDateString());

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'custom_interval_value' => 3,
            'custom_interval_unit' => 'year',
            'payment_terms' => 14,
        ])->assertOk();
        $subscription->refresh();
        $this->assertSame(3, $subscription->custom_interval_value);
        $this->assertSame('year', $subscription->custom_interval_unit);
        $this->assertSame($subscription->start_date->toDateString(), $subscription->next_billing_date->toDateString());

        $subscription->next_billing_date = '2028-01-01';
        $subscription->save();
        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'title' => 'Custom title changed',
            'payment_terms' => 14,
        ])->assertOk();
        $subscription->refresh();
        $this->assertSame('Custom title changed', $subscription->title);
        $this->assertSame(3, $subscription->custom_interval_value);
        $this->assertSame('year', $subscription->custom_interval_unit);
        $this->assertSame('2028-01-01', $subscription->next_billing_date->toDateString());
    }

    public function test_api_standard_custom_transitions_are_complete_and_normalized(): void
    {
        $this->actingAsPermissions();
        $subscription = $this->subjectSubscription($this->contract($this->company()));

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'billing_period' => 'custom',
            'custom_interval_value' => 45,
            'payment_terms' => 14,
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_interval_unit');

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'billing_period' => 'custom',
            'custom_interval_value' => 45,
            'custom_interval_unit' => 'day',
            'payment_terms' => 14,
        ])->assertOk();
        $subscription->refresh();
        $this->assertSame('custom', $subscription->billing_period);
        $this->assertSame(45, $subscription->custom_interval_value);
        $this->assertSame('day', $subscription->custom_interval_unit);

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'billing_period' => 'monthly',
            'payment_terms' => 14,
        ])->assertOk();
        $subscription->refresh();
        $this->assertSame('monthly', $subscription->billing_period);
        $this->assertNull($subscription->custom_interval_value);
        $this->assertNull($subscription->custom_interval_unit);

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'custom_interval_value' => 2,
            'custom_interval_unit' => 'month',
            'payment_terms' => 14,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'custom_interval_value',
            'custom_interval_unit',
        ]);
        $this->assertNull($subscription->fresh()->custom_interval_value);
        $this->assertNull($subscription->fresh()->custom_interval_unit);
    }

    public function test_api_partial_schedule_changes_are_rejected_for_all_history_states(): void
    {
        $this->actingAsPermissions();

        foreach (['draft', 'cancelled', 'issued'] as $index => $status) {
            $contract = $this->contract($this->company('API partial history '.$status));
            $subscription = $this->subjectSubscription($contract);
            $invoice = Invoice::query()->create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'invoice_number' => 'API-PARTIAL-HISTORY-'.$index,
                'issue_date' => '2026-08-01',
                'due_date' => '2026-08-15',
                'total_amount' => 100,
                'status' => $status,
            ]);
            $invoice->lines()->create([
                'subscription_id' => $subscription->id,
                'description' => 'History',
                'amount' => 100,
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
            ]);

            $payload = $status === 'draft'
                ? ['billing_period' => 'quarterly', 'payment_terms' => 14]
                : ['start_date' => '2026-10-01', 'payment_terms' => 14];
            $error = $status === 'draft' ? 'start_date' : 'start_date';

            $this->patchJson(route('api.subscriptions.update', $subscription), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($error);
            $this->assertSame('2026-08-01', $subscription->fresh()->start_date->toDateString());
            $this->assertSame('monthly', $subscription->fresh()->billing_period);
            $this->assertSame('2026-09-01', $subscription->fresh()->next_billing_date->toDateString());
        }
    }

    private function serviceType(): ServiceType
    {
        return ServiceType::query()->create([
            'name' => 'API lifecycle',
            'type' => 'subscription',
            'base_price' => 100,
        ]);
    }

    private function payload(int $serviceTypeId): array
    {
        return [
            'service_type_id' => $serviceTypeId,
            'start_date' => '2026-01-31',
            'billing_period' => 'monthly',
            'amount' => 100,
            'payment_terms' => 14,
            'status' => 'active',
        ];
    }
}
