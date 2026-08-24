<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyActivityEvent;
use App\Models\CompanyContact;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Invoice;
use App\Models\User;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class CompanyActivityQuery
{
    public const PER_PAGE = 25;

    public function paginate(
        User $user,
        Company $company,
        ?CompanyActivityCategory $category = null,
        ?string $cursor = null,
    ): CursorPaginatorContract {
        $query = CompanyActivityEvent::query()
            ->select([
                'id',
                'company_id',
                'actor_user_id',
                'event_type',
                'category',
                'visibility_scope',
                'subject_type',
                'subject_id',
                'occurred_at',
                'metadata',
            ])
            ->where('company_id', $company->getKey())
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $this->applyVisibility($query, $user, $company);

        if ($category !== null) {
            $query->where('category', $category->value);
        }

        return $query->cursorPaginate(
            perPage: self::PER_PAGE,
            columns: ['*'],
            cursorName: 'activity_cursor',
            cursor: $cursor,
        );
    }

    /**
     * Resolve only the known, linkable subject IDs on the already-filtered page.
     * These are bounded queries by subject type, never per event.
     *
     * @return array{contacts: array<int, bool>, contracts: array<int, bool>, invoices: array<int, bool>, document_contracts: array<int, int>}
     */
    public function availableSubjectIds(
        User $user,
        Company $company,
        CursorPaginatorContract $page,
    ): array {
        $events = collect($page->items());
        $ids = $events->groupBy('subject_type')->map(
            fn ($events) => $events->pluck('subject_id')->filter()->unique()->values()->all()
        );
        $metadataInvoiceIds = $events
            ->map(fn ($event) => is_array($event->metadata) ? $event->metadata['invoice_id'] ?? null : null)
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->all();
        $invoiceIds = array_values(array_unique([
            ...($ids->get('invoice', [])),
            ...$metadataInvoiceIds,
        ]));
        $available = [
            'contacts' => [],
            'contracts' => [],
            'invoices' => [],
            'document_contracts' => [],
        ];

        if (Gate::forUser($user)->allows('view', $company) && $ids->has('contact')) {
            $available['contacts'] = CompanyContact::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $ids->get('contact'))
                ->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => true])
                ->all();
        }

        if (Gate::forUser($user)->allows('viewAny', Contract::class) && $ids->has('contract')) {
            $available['contracts'] = Contract::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $ids->get('contract'))
                ->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => true])
                ->all();
        }

        if (Gate::forUser($user)->allows('viewFinancials', $company) && $invoiceIds !== []) {
            $available['invoices'] = Invoice::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $invoiceIds)
                ->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => true])
                ->all();
        }

        if (Gate::forUser($user)->allows('viewAny', Contract::class) && $ids->has('document')) {
            $available['document_contracts'] = ContractDocument::query()
                ->whereIn('id', $ids->get('document'))
                ->whereHas('contract', fn (Builder $query) => $query->where('company_id', $company->getKey()))
                ->pluck('contract_id', 'id')
                ->all();
        }

        return $available;
    }

    private function applyVisibility(Builder $query, User $user, Company $company): void
    {
        $allowedScopes = [];

        if (Gate::forUser($user)->allows('view', $company)) {
            $allowedScopes[] = CompanyActivityVisibilityScope::Company->value;
            $allowedScopes[] = CompanyActivityVisibilityScope::Contacts->value;
        }
        if (Gate::forUser($user)->allows('viewAny', Contract::class)) {
            $allowedScopes[] = CompanyActivityVisibilityScope::Contracts->value;
            $allowedScopes[] = CompanyActivityVisibilityScope::Documents->value;
        }
        if (Gate::forUser($user)->allows('viewFinancials', $company)) {
            $allowedScopes[] = CompanyActivityVisibilityScope::Financials->value;
        }

        if ($allowedScopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('visibility_scope', array_values(array_unique($allowedScopes)));
    }
}
