<?php

namespace Tests\Feature;

use App\Actions\Subscriptions\UpdateSubscription;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\Access\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Authorization\AuthorizationTestCase;

class SubscriptionLifecycleTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPermissions([
            PermissionName::ContractSubjectsCreate->value,
            PermissionName::ContractSubjectsUpdate->value,
        ]);
    }

    public function test_store_sets_server_managed_next_date_and_persists_custom_interval(): void
    {
        $contract = $this->contract($this->company());

        $this->post(route('contracts.subscriptions.store', $contract), [
            ...$this->webPayload('2026-01-31'),
            'billing_period' => 'custom',
            'custom_interval_value' => 2,
            'custom_interval_unit' => 'month',
            'next_billing_date' => '2035-01-01',
        ])->assertSessionDoesntHaveErrors();

        $subscription = Subscription::query()->sole();
        $this->assertSame('2026-01-31', $subscription->next_billing_date->toDateString());
        $this->assertSame(2, $subscription->custom_interval_value);
        $this->assertSame('month', $subscription->custom_interval_unit);
    }

    public function test_standard_period_clears_custom_fields(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()), [
            'billing_period' => 'custom',
            'custom_interval_value' => 45,
            'custom_interval_unit' => 'day',
        ]);

        $this->put(route('subscriptions.update', $subscription), [
            ...$this->webPayload('2026-08-01'),
            'title' => 'Standard',
            'billing_period' => 'monthly',
            'custom_interval_value' => 99,
            'custom_interval_unit' => 'year',
        ])->assertSessionDoesntHaveErrors();

        $subscription->refresh();
        $this->assertNull($subscription->custom_interval_value);
        $this->assertNull($subscription->custom_interval_unit);
    }

    public function test_ordinary_update_and_suspension_preserve_advanced_schedule(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()), [
            'next_billing_date' => '2027-03-31',
        ]);

        $this->put(route('subscriptions.update', $subscription), [
            ...$this->webPayload('2026-08-01'),
            'title' => 'Changed title',
            'amount' => 250,
            'payment_terms' => 7,
            'status' => 'suspended',
            'comment' => 'Changed comment',
        ])->assertSessionDoesntHaveErrors();

        $subscription->refresh();
        $this->assertSame('2027-03-31', $subscription->next_billing_date->toDateString());
        $this->assertSame('suspended', $subscription->status);
    }

    public function test_reactivation_preserves_future_date_and_moves_overdue_date_to_today(): void
    {
        CarbonImmutable::setTestNow('2026-07-30');

        try {
            $contract = $this->contract($this->company());
            $future = $this->subjectSubscription($contract, [
                'status' => 'suspended',
                'next_billing_date' => '2026-08-15',
            ]);
            $overdue = $this->subjectSubscription($contract, [
                'status' => 'completed',
                'next_billing_date' => '2026-01-01',
            ]);

            foreach ([$future, $overdue] as $subscription) {
                $this->put(route('subscriptions.update', $subscription), [
                    ...$this->webPayload('2026-08-01'),
                    'title' => $subscription->title,
                    'status' => 'active',
                ])->assertSessionDoesntHaveErrors();
            }

            $this->assertSame('2026-08-15', $future->fresh()->next_billing_date->toDateString());
            $this->assertSame('2026-07-30', $overdue->fresh()->next_billing_date->toDateString());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_schedule_change_without_history_resets_to_new_start(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()), [
            'next_billing_date' => '2027-01-01',
        ]);

        $this->put(route('subscriptions.update', $subscription), [
            ...$this->webPayload('2026-10-15'),
            'title' => $subscription->title,
            'billing_period' => 'quarterly',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-10-15', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_schedule_change_is_rejected_for_draft_cancelled_and_issued_history(): void
    {
        foreach (['draft', 'cancelled', 'issued', 'partially_paid', 'paid'] as $status) {
            $contract = $this->contract($this->company('History '.$status));
            $subscription = $this->subjectSubscription($contract);
            $invoice = Invoice::query()->create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'invoice_number' => 'HISTORY-'.strtoupper($status),
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

            $this->put(route('subscriptions.update', $subscription), [
                ...$this->webPayload('2026-10-01'),
                'title' => $subscription->title,
            ])->assertSessionHasErrors('start_date');

            $this->assertSame('2026-08-01', $subscription->fresh()->start_date->toDateString());
            $this->assertSame('2026-09-01', $subscription->fresh()->next_billing_date->toDateString());
        }
    }

    public function test_ordinary_update_with_history_is_allowed_and_preserves_schedule(): void
    {
        $contract = $this->contract($this->company());
        $subscription = $this->subjectSubscription($contract, [
            'next_billing_date' => '2027-01-01',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'invoice_number' => 'ORDINARY-HISTORY',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'total_amount' => 100,
            'status' => 'issued',
        ]);
        $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'History',
            'amount' => 100,
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
        ]);

        $this->put(route('subscriptions.update', $subscription), [
            ...$this->webPayload('2026-08-01'),
            'title' => 'Ordinary change',
            'amount' => 300,
            'payment_terms' => 7,
            'comment' => 'Allowed',
        ])->assertSessionDoesntHaveErrors();

        $subscription->refresh();
        $this->assertSame('Ordinary change', $subscription->title);
        $this->assertSame('2027-01-01', $subscription->next_billing_date->toDateString());
    }

    public function test_web_custom_pair_validation_and_standard_normalization_are_server_enforced(): void
    {
        $custom = $this->subjectSubscription($this->contract($this->company('Web custom')), [
            'billing_period' => 'custom',
            'custom_interval_value' => 2,
            'custom_interval_unit' => 'month',
        ]);

        $this->put(route('subscriptions.update', $custom), [
            ...$this->webPayload('2026-08-01'),
            'title' => $custom->title,
            'billing_period' => 'custom',
            'custom_interval_value' => 3,
            'custom_interval_unit' => null,
        ])->assertSessionHasErrors('custom_interval_unit');
        $this->assertSame(2, $custom->fresh()->custom_interval_value);

        $this->put(route('subscriptions.update', $custom), [
            ...$this->webPayload('2026-08-01'),
            'title' => $custom->title,
            'billing_period' => 'custom',
            'custom_interval_value' => 3,
            'custom_interval_unit' => 'year',
        ])->assertSessionDoesntHaveErrors();
        $this->assertSame(3, $custom->fresh()->custom_interval_value);
        $this->assertSame('year', $custom->fresh()->custom_interval_unit);

        $standard = $this->subjectSubscription($this->contract($this->company('Web standard')));
        $this->put(route('subscriptions.update', $standard), [
            ...$this->webPayload('2026-08-01'),
            'title' => $standard->title,
            'custom_interval_value' => 9,
            'custom_interval_unit' => 'month',
        ])->assertSessionDoesntHaveErrors();
        $this->assertNull($standard->fresh()->custom_interval_value);
        $this->assertNull($standard->fresh()->custom_interval_unit);
    }

    public function test_active_and_reactivation_date_boundaries_are_deterministic(): void
    {
        CarbonImmutable::setTestNow('2026-07-30');

        try {
            $contract = $this->contract($this->company());
            $active = $this->subjectSubscription($contract, [
                'status' => 'active',
                'next_billing_date' => '2026-01-01',
            ]);
            $cancelled = $this->subjectSubscription($contract, [
                'status' => 'cancelled',
                'next_billing_date' => '2026-01-01',
            ]);
            $today = $this->subjectSubscription($contract, [
                'status' => 'suspended',
                'next_billing_date' => '2026-07-30',
            ]);

            foreach ([$active, $cancelled, $today] as $subscription) {
                $this->put(route('subscriptions.update', $subscription), [
                    ...$this->webPayload('2026-08-01'),
                    'title' => $subscription->title,
                    'status' => 'active',
                ])->assertSessionDoesntHaveErrors();
            }

            $this->assertSame('2026-01-01', $active->fresh()->next_billing_date->toDateString());
            $this->assertSame('2026-07-30', $cancelled->fresh()->next_billing_date->toDateString());
            $this->assertSame('2026-07-30', $today->fresh()->next_billing_date->toDateString());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_service_type_and_subscription_creation_are_atomic(): void
    {
        $contract = $this->contract($this->company());
        $payload = $this->webPayload('2026-08-01');
        $payload['service_name'] = 'Must roll back';
        Subscription::creating(fn () => throw new \RuntimeException('Subscription insert failed'));

        $this->withoutExceptionHandling();

        try {
            $this->post(route('contracts.subscriptions.store', $contract), $payload);
            $this->fail('Expected the subscription insert to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Subscription insert failed', $exception->getMessage());
        } finally {
            Subscription::flushEventListeners();
        }

        $this->assertDatabaseMissing('service_types', ['name' => 'Must roll back']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    #[DataProvider('invalidInternalCustomIntervalProvider')]
    public function test_internal_update_rejects_invalid_custom_interval_without_mutations(
        mixed $value,
        mixed $unit,
        string $errorField,
    ): void {
        $contract = $this->contract($this->company());
        $subscription = $this->subjectSubscription($contract, [
            'next_billing_date' => '2027-01-01',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INVALID-INTERNAL-'.md5(serialize([$value, $unit])),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => 100,
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'Lifecycle guard',
            'amount' => 100,
            'period_start' => '2027-01-01',
            'period_end' => '2027-01-31',
        ]);
        $originalSubscription = (array) DB::table('subscriptions')
            ->where('id', $subscription->id)
            ->first();
        $originalDueDate = CarbonImmutable::parse($invoice->due_date)->toDateString();

        try {
            app(UpdateSubscription::class)->handle($subscription, [
                'title' => 'Must not be saved',
                'billing_period' => 'custom',
                'custom_interval_value' => $value,
                'custom_interval_unit' => $unit,
                'payment_terms' => 7,
                'status' => 'suspended',
            ]);
            $this->fail('Expected invalid internal custom interval to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorField, $exception->errors());
        }

        $this->assertSame(
            $originalSubscription,
            (array) DB::table('subscriptions')->where('id', $subscription->id)->first(),
        );
        $this->assertSame(
            $originalDueDate,
            CarbonImmutable::parse($invoice->fresh()->due_date)->toDateString(),
        );
    }

    public function test_internal_update_accepts_custom_interval_boundaries(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()), [
            'next_billing_date' => '2027-01-01',
        ]);

        foreach ([1, 3650] as $value) {
            app(UpdateSubscription::class)->handle($subscription, [
                'billing_period' => 'custom',
                'custom_interval_value' => $value,
                'custom_interval_unit' => 'day',
                'payment_terms' => 30,
            ]);

            $subscription->refresh();
            $this->assertSame('custom', $subscription->billing_period);
            $this->assertSame($value, $subscription->custom_interval_value);
            $this->assertSame('day', $subscription->custom_interval_unit);
        }
    }

    public static function invalidInternalCustomIntervalProvider(): array
    {
        return [
            'below minimum' => [0, 'day', 'custom_interval_value'],
            'above maximum' => [3651, 'month', 'custom_interval_value'],
            'invalid unit' => [2, 'fortnight', 'custom_interval_unit'],
        ];
    }

    private function webPayload(string $startDate): array
    {
        return [
            'service_name' => 'Lifecycle service',
            'title' => 'Lifecycle subscription',
            'start_date' => $startDate,
            'billing_period' => 'monthly',
            'amount' => 100,
            'payment_terms' => 14,
            'status' => 'active',
            'comment' => null,
        ];
    }
}
