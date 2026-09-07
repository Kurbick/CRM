<?php

namespace App\Actions\Invoices;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use App\Services\BillingPeriodPreview;
use App\Services\InvoicePaymentAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateBillingRunDrafts
{
    public function __construct(
        private readonly BillingPeriodPreview $preview,
        private readonly CreateInvoice $createInvoice,
        private readonly InvoicePaymentAvailabilityService $money,
    ) {}

    /**
     * @param  list<string>  $identities
     * @return array{created: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function execute(CarbonImmutable $period, array $identities, User $actor): array
    {
        $identities = $this->normalizeIdentities($identities);
        $previewRows = $this->preview->forPeriod($period)['rows'];
        $candidates = collect($previewRows)->keyBy('identity');
        $candidateRows = $candidates->filter(
            fn (array $row, string $identity): bool => in_array($identity, $identities, true)
        );
        $companies = Company::query()
            ->whereIn('id', $candidateRows->pluck('company_id')->unique()->values())
            ->get()
            ->keyBy('id');
        $contracts = Contract::query()
            ->whereIn('id', $candidateRows->pluck('contract_id')->unique()->values())
            ->get()
            ->keyBy('id');

        return DB::transaction(function () use ($identities, $candidates, $companies, $contracts, $actor): array {
            $created = [];
            $skipped = [];

            foreach ($identities as $identity) {
                $candidate = $candidates->get($identity);
                if (! $candidate) {
                    $skipped[] = [
                        'period' => null,
                        'reason' => 'already_invoiced',
                    ];
                    continue;
                }

                if (! ($candidate['eligible_for_draft_creation'] ?? false)) {
                    $skipped[] = [
                        'company' => $candidate['company'],
                        'contract' => $candidate['contract'],
                        'period' => $candidate['period'],
                        'reason' => 'already_invoiced',
                    ];
                    continue;
                }

                if ($candidate['next_billing_date'] !== $candidate['period_start']) {
                    $skipped[] = [
                        'company' => $candidate['company'],
                        'contract' => $candidate['contract'],
                        'period' => $candidate['period'],
                        'reason' => 'not_current',
                    ];
                    continue;
                }

                $company = $companies->get($candidate['company_id']);
                $contract = $contracts->get($candidate['contract_id']);
                if (! $company || ! $contract) {
                    $skipped[] = [
                        'period' => $candidate['period'],
                        'reason' => 'already_invoiced',
                    ];
                    continue;
                }

                try {
                    $invoice = $this->createInvoice->execute(
                        $company,
                        $contract,
                        [
                            'issue_date' => now()->toDateString(),
                        ],
                        [[
                            'subscription_id' => $candidate['subscription_id'],
                            'description' => $candidate['description'],
                            'amount' => '0.00',
                            'period_count' => 1,
                            'expected_period_start' => $candidate['period_start'],
                        ]],
                        canonicalizeSubjectAmounts: true,
                        actor: $actor,
                    );

                    $created[] = [
                        'id' => (int) $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'company' => $candidate['company'],
                        'contract' => $candidate['contract'],
                        'period' => $candidate['period'],
                        'total' => $invoice->total_amount,
                        'total_display' => $this->money->formatMinorUnits(
                            $this->money->toMinorUnits($invoice->total_amount)
                        ),
                    ];
                } catch (ValidationException $exception) {
                    if (! $this->isOccurrenceConflict($exception)) {
                        throw $exception;
                    }

                    $skipped[] = [
                        'company' => $candidate['company'],
                        'contract' => $candidate['contract'],
                        'period' => $candidate['period'],
                        'reason' => 'already_invoiced',
                    ];
                }
            }

            return compact('created', 'skipped');
        });
    }

    /** @param list<string> $identities @return list<string> */
    private function normalizeIdentities(array $identities): array
    {
        $normalized = [];
        foreach ($identities as $identity) {
            if (! is_string($identity)
                || preg_match('/^(\d+):([a-f0-9]{64})$/', $identity) !== 1) {
                throw ValidationException::withMessages([
                    'selected_occurrences' => __('invoices.billing_page.invalid_selection'),
                ]);
            }

            $normalized[] = $identity;
        }

        return array_values(array_unique($normalized));
    }

    private function isOccurrenceConflict(ValidationException $exception): bool
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                $message = mb_strtolower((string) $message);
                if (str_contains($message, 'billing occurrence')
                    || str_contains($message, 'уже зарезервирован')
                    || str_contains($message, 'уже существует')) {
                    return true;
                }
            }
        }

        return false;
    }
}
