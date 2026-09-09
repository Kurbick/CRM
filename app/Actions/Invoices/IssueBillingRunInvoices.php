<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Services\ActiveOrganizationContext;
use App\Services\BillingPeriodPreview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class IssueBillingRunInvoices
{
    public function __construct(
        private readonly BillingPeriodPreview $preview,
        private readonly ActiveOrganizationContext $organizationContext,
        private readonly IssueInvoice $issueInvoice,
    ) {}

    /**
     * @param list<int> $invoiceIds
     * @return array{issued: list<int>, skipped: list<array<string, mixed>>}
     */
    public function execute(CarbonImmutable $period, array $invoiceIds, User $actor): array
    {
        $invoiceIds = array_values(array_unique(array_map(static fn (int $id): int => $id, $invoiceIds)));
        $candidateRows = collect($this->preview->forPeriod($period)['rows'])
            ->filter(fn (array $row): bool => $row['queue_status'] === 'draft' && $row['invoice_id'] !== null)
            ->keyBy(fn (array $row): int => (int) $row['invoice_id']);
        $organization = $this->organizationContext->resolve();
        $issued = [];
        $skipped = [];

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::query()
                ->tap(fn ($query) => $this->organizationContext->scopeFor($query, $organization))
                ->whereKey($invoiceId)
                ->first();

            if ($invoice === null) {
                $skipped[] = ['reason' => 'not_current'];
                continue;
            }

            Gate::authorize('issue', $invoice);

            $candidate = $candidateRows->get($invoiceId);
            if ($candidate === null || $invoice->status !== 'draft') {
                $skipped[] = $this->skipDetails($invoice, 'already_invoiced');
                continue;
            }

            try {
                $this->issueInvoice->execute(
                    $invoice,
                    actor: $actor,
                    billingPeriod: $period,
                );
                $issued[] = $invoiceId;
            } catch (ValidationException|ModelNotFoundException) {
                // A concurrent issue (or another stale lifecycle condition) is safe to skip.
                $skipped[] = $this->skipDetails($invoice, 'not_current');
            }
        }

        return compact('issued', 'skipped');
    }

    /** @return array<string, mixed> */
    private function skipDetails(Invoice $invoice, string $reason): array
    {
        return [
            'company' => $invoice->company?->name ?? $invoice->payer_name,
            'contract' => $invoice->contract?->contract_number ?? $invoice->contract_reference,
            'reason' => $reason,
        ];
    }
}
