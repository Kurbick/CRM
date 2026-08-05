<?php

namespace Tests\Feature;

use App\Actions\Payments\CreatePendingPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoicePaymentAvailabilityService;
use App\Support\Access\PermissionName;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiPaymentCreationIntegrityTest extends AuthorizationTestCase
{
    private const PAYMENT_KEYS = [
        'id',
        'invoice_id',
        'amount',
        'payment_date',
        'payment_method',
        'status',
        'comment',
        'created_at',
        'updated_at',
    ];

    public function test_issued_and_partially_paid_invoices_create_only_pending_payments_with_safe_projection(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        foreach (['issued', 'partially_paid'] as $status) {
            $invoice = $this->invoice($status, 'API-PAYMENT-CREATE-'.strtoupper($status));
            $originalStatus = $invoice->status;

            $response = $this->postJson(
                route('api.invoices.payments.store', $invoice),
                $this->payload('12.30', comment: $status === 'issued' ? 'unique-api-comment' : null),
            );

            $response->assertCreated();
            $this->assertSame(self::PAYMENT_KEYS, array_keys($response->json()));
            $response
                ->assertJsonPath('invoice_id', $invoice->id)
                ->assertJsonPath('amount', '12.30')
                ->assertJsonPath('payment_date', '2026-08-05')
                ->assertJsonPath('payment_method', 'transfer')
                ->assertJsonPath('status', 'pending')
                ->assertJsonPath('comment', $status === 'issued' ? 'unique-api-comment' : null);

            $this->assertDatabaseHas('payments', [
                'id' => $response->json('id'),
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'amount' => '12.30',
                'status' => 'pending',
            ]);
            $this->assertSame($originalStatus, $invoice->fresh()->status);
        }

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_draft_paid_and_cancelled_states_are_rejected_for_regular_user_and_administrator(): void
    {
        foreach (['permission', 'administrator'] as $actor) {
            foreach (['draft', 'paid', 'cancelled'] as $status) {
                $invoice = $this->invoice($status, 'API-PAY-ST-'.$actor[0].'-'.$status);
                if ($actor === 'administrator') {
                    $user = User::factory()->create();
                    $user->assignRole('administrator');
                    $this->actingAs($user, 'web');
                } else {
                    $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
                }

                $this->postJson(route('api.invoices.payments.store', $invoice), $this->payload())
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('payment');
                $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
                $this->assertSame($status, $invoice->fresh()->status);
            }
        }
    }

    public function test_server_owned_and_internal_fields_are_explicitly_prohibited(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        $fields = [
            'id', 'invoice_id', 'company_id', 'status', 'cancelled_at', 'cancel_reason',
            'confirmed_at', 'reference', 'allocations', 'payment_allocations', 'credit_balance',
            'credit_balance_id', 'credit_balance_entry_id', 'source_payment_id', 'source_invoice_id',
            'created_at', 'updated_at',
        ];

        foreach ($fields as $field) {
            $invoice = $this->invoice('issued', 'API-PAY-PROH-'.substr(sha1($field), 0, 8));
            $payload = $this->payload();
            $payload[$field] = match ($field) {
                'status' => 'pending',
                'invoice_id', 'company_id', 'id' => $invoice->id,
                default => 'internal-marker',
            };

            $this->postJson(route('api.invoices.payments.store', $invoice), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
            $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        }

        foreach ([
            ['invoice_id' => 1_000_000],
            ['company_id' => 1_000_000],
            ['status' => 'confirmed'],
        ] as $spoofed) {
            $invoice = $this->invoice('issued', 'API-PAY-SPOOF-'.uniqid());
            $this->postJson(
                route('api.invoices.payments.store', $invoice),
                [...$this->payload(), ...$spoofed],
            )->assertUnprocessable()->assertJsonValidationErrors(array_key_first($spoofed));
            $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        }
    }

    public function test_strict_decimal_contract_accepts_canonical_values_through_schema_maximum(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        foreach ([
            '0.01' => '0.01',
            '1' => '1.00',
            '1.2' => '1.20',
            '1.20' => '1.20',
            '99999999.99' => '99999999.99',
        ] as $amount => $expected) {
            $invoice = $this->invoice('issued', 'API-PAY-AMT-'.substr(sha1($amount), 0, 8));
            $invoice->forceFill(['total_amount' => '99999999.99'])->save();

            $this->postJson(route('api.invoices.payments.store', $invoice), $this->payload($amount))
                ->assertCreated()
                ->assertJsonPath('amount', $expected);
        }

        $this->assertDatabaseHas('payments', ['amount' => '0.01']);
        $this->assertDatabaseHas('payments', ['amount' => '1.00']);
        $this->assertDatabaseHas('payments', ['amount' => '1.20']);
        $this->assertDatabaseHas('payments', ['amount' => '99999999.99']);
    }

    public function test_strict_decimal_and_date_contract_rejects_noncanonical_values_before_transaction(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        $invoice = $this->invoice('issued', 'API-PAYMENT-AMOUNT-REJECT');

        foreach (['0', '-0.01', '1.001', '1e2', '+1.00', '1,00', 'NaN', 'INF', '', ' ', '100000000.00'] as $amount) {
            $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
                route('api.invoices.payments.store', $invoice),
                $this->payload($amount),
            ));
            $capture['result']->assertUnprocessable()->assertJsonValidationErrors('amount');
            $this->assertSame(['invoices'], DomainQueryRecorder::tables($capture['records']));
            $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
        }

        foreach (['2026-02-30', '2026-8-05', '2026-08-05T00:00:00Z'] as $date) {
            $this->postJson(route('api.invoices.payments.store', $invoice), $this->payload(date: $date))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('payment_date');
        }
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
    }

    public function test_pending_availability_reserves_confirmed_and_pending_but_ignores_cancelled_and_negative_legacy_rows(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        $invoice = $this->invoice('partially_paid', 'API-PAYMENT-AVAILABILITY');
        $this->insertPayment($invoice, 'confirmed', '20.00');
        $this->insertPayment($invoice, 'pending', '30.00');
        $this->insertPayment($invoice, 'pending', '10.00');
        $this->insertPayment($invoice, 'cancelled', '90.00');
        $this->insertPayment($invoice, 'pending', '-50.00');

        $this->postJson(route('api.invoices.payments.store', $invoice), $this->payload('40.00'))
            ->assertCreated();
        $this->postJson(route('api.invoices.payments.store', $invoice), $this->payload('0.01'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');
    }

    public function test_amount_below_equal_and_above_available_have_stable_results_without_over_reservation(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        $below = $this->invoice('issued', 'API-PAYMENT-BELOW');
        $this->postJson(route('api.invoices.payments.store', $below), $this->payload('99.99'))->assertCreated();

        $equal = $this->invoice('issued', 'API-PAYMENT-EQUAL');
        $this->postJson(route('api.invoices.payments.store', $equal), $this->payload('100.00'))->assertCreated();

        $above = $this->invoice('issued', 'API-PAYMENT-ABOVE');
        $this->insertPayment($above, 'pending', '60.00');
        $this->postJson(route('api.invoices.payments.store', $above), $this->payload('40.01'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('payments', 3);
    }

    public function test_action_requeries_stale_invoice_and_structurally_locks_it_inside_one_transaction(): void
    {
        $invoice = $this->invoice('issued', 'API-PAYMENT-STALE');
        $invoice->newQuery()->whereKey($invoice->id)->update(['status' => 'cancelled']);

        try {
            app(CreatePendingPayment::class)->execute($invoice, $this->payload());
            $this->fail('A stale route-bound Invoice must not authorize a pending reservation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }

        $source = file_get_contents(app_path('Actions/Payments/CreatePendingPayment.php'));
        $this->assertIsString($source);
        $this->assertSame(1, substr_count($source, 'DB::transaction'));
        $this->assertSame(1, substr_count($source, 'lockForUpdate()'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
    }

    public function test_unexpected_dependency_failure_propagates_and_rolls_back_without_side_effects(): void
    {
        $invoice = $this->invoice('issued', 'API-PAYMENT-ROLLBACK');
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        $this->mock(InvoicePaymentAvailabilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('evaluatePendingCreation')->once()->andThrow(new RuntimeException('rollback-marker'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson(route('api.invoices.payments.store', $invoice), $this->payload());
            $this->fail('Unexpected dependency failures must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback-marker', $exception->getMessage());
        }

        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_success_and_conflict_queries_are_bounded_without_related_domain_queries_or_n_plus_one(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        foreach ([1, 6] as $existingCount) {
            $invoice = $this->invoice('issued', 'API-PAYMENT-QUERY-'.$existingCount);
            foreach (range(1, $existingCount) as $index) {
                $this->insertPayment($invoice, 'pending', '1.00');
            }

            $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
                route('api.invoices.payments.store', $invoice),
                $this->payload('1.00'),
            ));
            $capture['result']->assertCreated();
            $this->assertSame(['invoices', 'payments'], DomainQueryRecorder::tables($capture['records']));
            $this->assertSame(4, DomainQueryRecorder::count($capture['records']));
        }

        $conflict = $this->invoice('issued', 'API-PAYMENT-QUERY-CONFLICT');
        $this->insertPayment($conflict, 'pending', '100.00');
        $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
            route('api.invoices.payments.store', $conflict),
            $this->payload('0.01'),
        ));
        $capture['result']->assertUnprocessable();
        $this->assertSame(['invoices', 'payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));
    }

    /** @return array<string, string|null> */
    private function payload(
        string $amount = '10.00',
        string $date = '2026-08-05',
        ?string $comment = 'API pending creation'
    ): array {
        return [
            'payment_date' => $date,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'comment' => $comment,
        ];
    }

    private function insertPayment(Invoice $invoice, string $status, string $amount): void
    {
        Payment::query()->insert([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-01',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
