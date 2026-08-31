<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class InvoiceDueDateContractBoundaryTest extends AuthorizationTestCase
{
    public function test_order_due_date_inside_contract_term_is_allowed(): void
    {
        [$company, $contract] = $this->contractWithEnd('ORDER-INSIDE', '2026-12-15');
        $order = $this->subjectOrder($contract, ['payment_terms' => 60]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->post(route('invoices.store'), $this->webPayload(
            $company,
            $contract,
            [$this->orderLine($order)],
            '2026-10-15',
            'ORDER-INSIDE'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-12-14', Invoice::query()->sole()->due_date);
    }

    public function test_order_due_date_equal_to_contract_end_is_allowed(): void
    {
        [$company, $contract] = $this->contractWithEnd('ORDER-EQUAL', '2026-12-14');
        $order = $this->subjectOrder($contract, ['payment_terms' => 60]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->post(route('invoices.store'), $this->webPayload(
            $company,
            $contract,
            [$this->orderLine($order)],
            '2026-10-15',
            'ORDER-EQUAL'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-12-14', Invoice::query()->sole()->due_date);
    }

    public function test_order_due_date_after_contract_end_is_rejected_without_write(): void
    {
        [$company, $contract] = $this->contractWithEnd('ORDER-AFTER', '2026-11-01');
        $order = $this->subjectOrder($contract, ['payment_terms' => 60]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $response = $this->from(route('invoices.create'))->post(
            route('invoices.store'),
            $this->webPayload($company, $contract, [$this->orderLine($order)], '2026-10-15', 'ORDER-AFTER')
        );

        $response->assertRedirect(route('invoices.create'))->assertSessionHasErrors('due_date');
        $this->assertSame([
            __('invoices.errors.due_date_after_contract_end', [
                'days' => 60,
                'due_date' => '14/12/2026',
                'contract_end_date' => '01/11/2026',
            ]),
        ], $response->getSession()->get('errors')->get('due_date'));
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_subscription_due_date_equal_to_contract_end_is_allowed(): void
    {
        [$company, $contract] = $this->contractWithEnd('SUB-EQUAL', '2026-12-14');
        $subscription = $this->subjectSubscription($contract, [
            'payment_terms' => 60,
            'next_billing_date' => '2026-10-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->apiPayload($contract, [$this->subscriptionLine($subscription)], 'SUB-EQUAL', '2026-10-15')
        )->assertCreated()->assertJsonPath('due_date', '2026-12-14');
    }

    public function test_subscription_due_date_after_contract_end_is_rejected(): void
    {
        [$company, $contract] = $this->contractWithEnd('SUB-AFTER', '2026-11-01');
        $subscription = $this->subjectSubscription($contract, [
            'payment_terms' => 60,
            'next_billing_date' => '2026-10-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->apiPayload($contract, [$this->subscriptionLine($subscription)], 'SUB-AFTER', '2026-10-15')
        )->assertUnprocessable()->assertJsonValidationErrors('due_date');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_multi_period_subscription_keeps_payment_terms_for_boundary_calculation(): void
    {
        [$company, $contract] = $this->contractWithEnd('SUB-MULTI', '2026-12-14');
        $subscription = $this->subjectSubscription($contract, [
            'payment_terms' => 60,
            'next_billing_date' => '2026-10-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->post(route('invoices.store'), $this->webPayload(
            $company,
            $contract,
            [[
                ...$this->subscriptionLine($subscription),
                'period_count' => 2,
                'expected_period_start' => '2026-10-01',
            ]],
            '2026-10-15',
            'SUB-MULTI'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-12-14', Invoice::query()->sole()->due_date);
        $this->assertCount(2, Invoice::query()->sole()->lines);
    }

    public function test_multiple_subjects_use_minimum_payment_terms_for_boundary(): void
    {
        [$company, $contract] = $this->contractWithEnd('MIXED-TERMS', '2026-11-14');
        $order = $this->subjectOrder($contract, ['payment_terms' => 60]);
        $subscription = $this->subjectSubscription($contract, [
            'payment_terms' => 30,
            'next_billing_date' => '2026-10-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->apiPayload($contract, [
                $this->orderLine($order),
                $this->subscriptionLine($subscription),
            ], 'MIXED-TERMS', '2026-10-15')
        )->assertCreated()->assertJsonPath('due_date', '2026-11-14');
    }

    public function test_issue_date_after_contract_end_has_a_separate_validation_error(): void
    {
        [$company, $contract] = $this->contractWithEnd('ISSUE-AFTER', '2026-10-01');
        $order = $this->subjectOrder($contract, ['payment_terms' => 0]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->apiPayload($contract, [$this->orderLine($order)], 'ISSUE-AFTER', '2026-10-02')
        )->assertUnprocessable()->assertJsonValidationErrors('issue_date');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_web_update_rejects_issue_date_that_moves_due_date_after_contract_end(): void
    {
        [$company, $contract] = $this->contractWithEnd('WEB-UPDATE', '2026-11-14');
        $order = $this->subjectOrder($contract, ['payment_terms' => 30]);
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesUpdate->value,
        ]);
        $this->post(route('invoices.store'), $this->webPayload(
            $company,
            $contract,
            [$this->orderLine($order)],
            '2026-10-01',
            'WEB-UPDATE'
        ))->assertSessionDoesntHaveErrors();
        $invoice = Invoice::query()->sole();
        $line = $invoice->lines()->sole();

        $response = $this->from(route('invoices.edit', $invoice))->put(
            route('invoices.update', $invoice),
            $this->updatePayload($invoice, $line, '2026-10-16')
        );

        $response->assertRedirect(route('invoices.edit', $invoice))->assertSessionHasErrors('due_date');
        $this->assertSame('2026-10-01', $invoice->fresh()->issue_date);
        $this->assertSame('2026-10-31', $invoice->fresh()->due_date);
    }

    public function test_api_update_rejects_invalid_issue_date_but_allows_unrelated_update(): void
    {
        [$company, $contract] = $this->contractWithEnd('API-UPDATE', '2026-11-14');
        $order = $this->subjectOrder($contract, ['payment_terms' => 30]);
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesUpdate->value,
        ]);
        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->apiPayload($contract, [$this->orderLine($order)], 'API-UPDATE', '2026-10-01')
        )->assertCreated();
        $invoice = Invoice::query()->sole();

        $this->patchJson(route('api.invoices.update', $invoice), [
            'issue_date' => '2026-10-16',
        ])->assertUnprocessable()->assertJsonValidationErrors('due_date');

        $this->patchJson(route('api.invoices.update', $invoice), [
            'comment' => 'Unrelated update',
        ])->assertOk()->assertJsonPath('comment', 'Unrelated update');
        $this->assertSame('2026-10-01', $invoice->fresh()->issue_date);
        $this->assertSame('2026-10-31', $invoice->fresh()->due_date);
    }

    public function test_contract_without_end_date_keeps_standard_due_date_calculation(): void
    {
        [$company, $contract] = $this->contractWithEnd('NO-END', null);
        $order = $this->subjectOrder($contract, ['payment_terms' => 60]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->apiPayload($contract, [$this->orderLine($order)], 'NO-END', '2026-10-15')
        )->assertCreated()->assertJsonPath('due_date', '2026-12-14');
    }

    public function test_boundary_errors_are_localized_in_ru_and_az(): void
    {
        foreach (['ru', 'az'] as $locale) {
            $this->app->setLocale($locale);
            [$company, $contract] = $this->contractWithEnd('LOCALIZED-'.$locale, '2026-11-01');
            $order = $this->subjectOrder($contract, ['payment_terms' => 60]);
            $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

            $response = $this->from(route('invoices.create'))->post(
                route('invoices.store'),
                $this->webPayload($company, $contract, [$this->orderLine($order)], '2026-10-15', 'LOCALIZED-'.$locale)
            );

            $response->assertSessionHasErrors(['due_date' => __('invoices.errors.due_date_after_contract_end', [
                'days' => 60,
                'due_date' => '14/12/2026',
                'contract_end_date' => '01/11/2026',
            ])]);
        }
    }

    /** @return array{0: Company, 1: Contract} */
    private function contractWithEnd(string $suffix, ?string $endDate): array
    {
        $company = $this->company('Due date '.$suffix);
        $contract = $this->contract($company);
        $contract->update(['end_date' => $endDate]);

        return [$company, $contract->fresh()];
    }

    /** @param array<string, mixed> $line */
    private function webPayload(Company $company, Contract $contract, array $lines, string $issueDate, string $number): array
    {
        return [
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => $issueDate,
            'due_date' => '2030-01-01',
            'lines' => $lines,
        ];
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private function apiPayload(Contract $contract, array $lines, string $number, string $issueDate): array
    {
        return [
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => $issueDate,
            'due_date' => '2030-01-01',
            'total_amount' => '999.99',
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    private function orderLine(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'description' => $order->title,
            'amount' => $order->price,
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionLine(Subscription $subscription): array
    {
        return [
            'subscription_id' => $subscription->id,
            'description' => $subscription->title,
            'amount' => $subscription->amount,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(Invoice $invoice, $line, string $issueDate): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $issueDate,
            'due_date' => '2030-01-01',
            'lines' => [[
                'id' => $line->id,
                'description' => $line->description,
                'amount' => $line->amount,
                'subscription_id' => null,
                'order_id' => $line->order_id,
                'period_start' => null,
                'period_end' => null,
            ]],
        ];
    }
}
