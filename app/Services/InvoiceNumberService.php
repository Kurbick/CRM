<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceNumberCounter;
use App\Models\Organization;
use App\Support\Invoices\InvoiceNumberFormatter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class InvoiceNumberService
{
    public const MAX_CODE_LENGTH = 12;

    public function __construct(private readonly InvoiceNumberFormatter $formatter) {}

    /**
     * Return the next candidate without creating or updating a counter row.
     *
     * @return array{sequence:int, code:string, year:int, formatted:string}
     */
    public function preview(Organization $organization, int $year): array
    {
        $code = $this->code($organization);
        $lastSequence = InvoiceNumberCounter::query()
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->value('last_sequence');

        if ($lastSequence === null) {
            $lastSequence = Invoice::query()
                ->where('issuer_organization_id', $organization->getKey())
                ->where('invoice_number_year', $year)
                ->max('invoice_number_sequence') ?? 0;
        }

        $sequence = (int) $lastSequence + 1;

        return [
            'sequence' => $sequence,
            'code' => $code,
            'year' => $year,
            'formatted' => $this->formatter->format($sequence, $code, $year),
        ];
    }

    /**
     * Allocate a number in the caller's transaction.
     *
     * Legacy full invoice numbers are preserved for old API clients and
     * historical test/import paths. Structured requests use this allocator.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function allocateForCreate(array $attributes): array
    {
        if ($this->isLegacy($attributes)) {
            return ['invoice_number' => (string) $attributes['invoice_number']];
        }

        $organization = $this->currentOrganization();
        $year = $this->year($attributes['issue_date'] ?? null);
        $manual = $this->boolean($attributes['invoice_number_manual'] ?? false);
        $sequence = $manual ? $this->positiveSequence($attributes['invoice_number_sequence'] ?? null) : null;

        return $this->allocate($organization, $year, $sequence);
    }

    /**
     * Resolve number changes for an editable invoice. The caller must already
     * hold the invoice lock and remain inside its transaction.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function changesForUpdate(Invoice $invoice, array $attributes): array
    {
        $hasSequence = array_key_exists('invoice_number_sequence', $attributes);
        $hasLegacyNumber = array_key_exists('invoice_number', $attributes);

        if (! $hasSequence) {
            if ($hasLegacyNumber && (string) $attributes['invoice_number'] !== (string) $invoice->invoice_number) {
                return ['invoice_number' => (string) $attributes['invoice_number']];
            }

            if ($invoice->invoice_number_year !== null && array_key_exists('issue_date', $attributes)) {
                $year = $this->year($attributes['issue_date']);
                if ($year !== (int) $invoice->invoice_number_year) {
                    return $this->allocate(
                        $this->organizationForInvoice($invoice),
                        $year,
                        null,
                        $invoice,
                    );
                }
            }

            return [];
        }

        $year = $this->year($attributes['issue_date'] ?? $invoice->issue_date);
        $currentYear = $invoice->invoice_number_year !== null
            ? (int) $invoice->invoice_number_year
            : null;
        $currentSequence = $invoice->invoice_number_sequence !== null
            ? (int) $invoice->invoice_number_sequence
            : null;
        $requested = $this->positiveSequence($attributes['invoice_number_sequence']);
        $explicitManual = $this->boolean($attributes['invoice_number_manual'] ?? false);
        $manual = $explicitManual
            || $currentSequence === null
            || $requested !== $currentSequence
            || $year !== $currentYear;

        if (! $manual && $currentYear === $year && $currentSequence !== null) {
            return [];
        }

        $organization = $this->organizationForInvoice($invoice);
        $allocation = $this->allocate(
            $organization,
            $year,
            $explicitManual || ($currentSequence === null || $requested !== $currentSequence)
                ? $requested
                : null,
            $invoice,
        );

        return $allocation;
    }

    /** @return array<string,mixed> */
    private function allocate(
        Organization $organization,
        int $year,
        ?int $manualSequence,
        ?Invoice $ignoreInvoice = null,
    ): array {
        $code = $this->code($organization);
        $counter = InvoiceNumberCounter::query()
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $initial = (int) (Invoice::query()
                ->where('issuer_organization_id', $organization->getKey())
                ->where('invoice_number_year', $year)
                ->max('invoice_number_sequence') ?? 0);

            try {
                $counter = InvoiceNumberCounter::query()->create([
                    'organization_id' => $organization->getKey(),
                    'year' => $year,
                    'last_sequence' => $initial,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another transaction created the first row. Its unique key
                // is the serialization point; lock and use its high-water.
                $counter = InvoiceNumberCounter::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        $sequence = $manualSequence ?? ((int) $counter->last_sequence + 1);
        $this->assertSequenceAvailable($organization, $year, $sequence, $ignoreInvoice);

        if ($sequence > (int) $counter->last_sequence) {
            $counter->update(['last_sequence' => $sequence]);
        }

        return [
            'issuer_organization_id' => $organization->getKey(),
            'invoice_number_year' => $year,
            'invoice_number_sequence' => $sequence,
            'invoice_number_code' => $code,
            'invoice_number' => $this->formatter->format($sequence, $code, $year),
        ];
    }

    private function assertSequenceAvailable(
        Organization $organization,
        int $year,
        int $sequence,
        ?Invoice $ignoreInvoice,
    ): void {
        $query = Invoice::query()
            ->where('issuer_organization_id', $organization->getKey())
            ->where('invoice_number_year', $year)
            ->where('invoice_number_sequence', $sequence);

        if ($ignoreInvoice) {
            $query->where($query->getModel()->getQualifiedKeyName(), '!=', $ignoreInvoice->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'invoice_number_sequence' => __('invoices.errors.number_sequence_taken'),
            ]);
        }
    }

    private function currentOrganization(): Organization
    {
        $organization = Organization::query()->current()->lockForUpdate()->first();
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => __('invoices.errors.organization_not_configured'),
            ]);
        }

        return $organization;
    }

    private function organizationForInvoice(Invoice $invoice): Organization
    {
        if ($invoice->issuer_organization_id !== null) {
            $organization = Organization::query()
                ->whereKey($invoice->issuer_organization_id)
                ->lockForUpdate()
                ->first();
            if ($organization) {
                return $organization;
            }
        }

        return $this->currentOrganization();
    }

    private function code(Organization $organization): string
    {
        $code = strtoupper(trim((string) $organization->invoice_number_code));
        if ($code === '') {
            throw ValidationException::withMessages([
                'organization' => __('invoices.errors.organization_number_code_missing'),
            ]);
        }

        if (strlen($code) > self::MAX_CODE_LENGTH || preg_match('/^[A-Z0-9]+$/', $code) !== 1) {
            throw ValidationException::withMessages([
                'organization' => __('invoices.errors.organization_number_code_invalid'),
            ]);
        }

        return $code;
    }

    private function year(mixed $date): int
    {
        if ($date === null || trim((string) $date) === '') {
            throw ValidationException::withMessages([
                'issue_date' => __('validation.date'),
            ]);
        }

        return (int) date('Y', strtotime((string) $date));
    }

    private function positiveSequence(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw ValidationException::withMessages([
                'invoice_number_sequence' => __('invoices.errors.number_sequence_invalid'),
            ]);
        }

        return (int) $value;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function isLegacy(array $attributes): bool
    {
        return array_key_exists('invoice_number', $attributes)
            && ! array_key_exists('invoice_number_sequence', $attributes);
    }
}
