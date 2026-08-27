<?php

namespace App\Http\Controllers\Web;

use App\Actions\Contracts\CreateContract;
use App\Actions\Contracts\DeleteContract;
use App\Actions\Contracts\UpdateContract;
use App\Exceptions\ContractDeletionException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Support\Access\PermissionName;
use App\Support\CompanyPageContext;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Contract::class);

        $search = mb_substr(trim((string) $request->input('search', '')), 0, 255);
        $status = in_array($request->input('status'), ['active', 'terminated'], true)
            ? $request->input('status')
            : null;
        $companyId = filter_var($request->input('company_id'), FILTER_VALIDATE_INT);
        $companyId = $companyId !== false && $companyId > 0 ? $companyId : null;

        $query = Contract::query()
            ->with('company:id,name');

        /*
     * Поиск по номеру договора
     * или названию компании.
     */
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query
                    ->where(
                        'contract_number',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhereHas(
                        'company',
                        function ($companyQuery) use ($search) {
                            $companyQuery->where(
                                'name',
                                'like',
                                "%{$search}%"
                            );
                        }
                    );
            });
        }

        /*
     * Фильтрация по фактическому статусу.
     */
        if ($status !== null) {
            switch ($status) {
                case 'active':
                    $query
                        ->where('status', 'active')
                        ->where(function ($query) {
                            $query
                                ->whereNull('end_date')
                                ->orWhereDate(
                                    'end_date',
                                    '>=',
                                    today()
                                );
                        });

                    break;

                case 'terminated':
                    $query->where(
                        'status',
                        'terminated'
                    );

                    break;
            }
        }

        /*
     * Фильтрация по компании.
     */
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        /*
     * Разрешённые параметры сортировки.
     */
        $allowedSortColumns = [
            'start_date',
            'end_date',
        ];

        $allowedSortDirections = [
            'asc',
            'desc',
        ];

        $sortBy = $request->input(
            'sort_by',
            'start_date'
        );

        $sortDirection = $request->input(
            'sort_direction',
            'desc'
        );

        if (
            ! in_array(
                $sortBy,
                $allowedSortColumns,
                true
            )
        ) {
            $sortBy = 'start_date';
        }

        if (
            ! in_array(
                $sortDirection,
                $allowedSortDirections,
                true
            )
        ) {
            $sortDirection = 'desc';
        }

        /*
     * Сортировка по выбранной дате.
     *
     * При сортировке по окончанию
     * бессрочные договоры всегда находятся внизу.
     */
        if ($sortBy === 'end_date') {
            $query
                ->orderByRaw('end_date IS NULL')
                ->orderBy(
                    'end_date',
                    $sortDirection
                );
        } else {
            $query->orderBy(
                'start_date',
                $sortDirection
            );
        }

        /*
     * Стабильный порядок при одинаковых датах.
     */
        $query->orderByDesc('id');

        $paginationParameters = array_filter([
            'search' => $search !== '' ? $search : null,
            'status' => $status,
            'company_id' => $companyId,
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $contracts = $query
            ->paginate(15)
            ->appends($paginationParameters);

        $companies = Company::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        $contractEditContext = [
            'edit_origin' => 'index',
            ...$this->contractIndexReturnParameters($request),
        ];

        return view(
            'contracts.index',
            compact(
                'contracts',
                'companies',
                'contractEditContext',
                'search',
                'status',
                'companyId',
                'sortBy',
                'sortDirection',
                'paginationParameters'
            )
        );
    }

    public function create(Request $request, ?Company $company = null)
    {
        Gate::authorize('create', Contract::class);

        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($company !== null) {
            $company = Company::query()
                ->select(['id', 'name'])
                ->findOrFail($company->getKey());
        }

        $companyContext = $company ? $this->safeCompanyContext($request, $company) : null;
        $backUrl = $this->createBackUrl($company, $companyContext);

        return view(
            'contracts.create',
            compact('company', 'companies', 'companyContext', 'backUrl')
        );
    }

    public function store(Request $request, CreateContract $createContract)
    {
        Gate::authorize('create', Contract::class);

        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'contract_number' => 'required|string|max:50|unique:contracts,contract_number',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => 'required|in:active,terminated',
            'signed_document' => 'prohibited',
            'comment' => 'nullable|string',
        ]);

        $company = Company::query()->findOrFail($validated['company_id']);
        $contract = $createContract->handle($company, $validated, $request->user());

        return $this->mutationRedirect($request, $contract)
            ->with('success', __('contracts.flash.created'));
    }

    public function show(
        Request $request,
        Contract $contract,
        DeleteContract $deleteContract
    ) {
        Gate::authorize('view', $contract);

        $canUploadDocuments = Gate::allows(PermissionName::ContractDocumentsUpload->value);
        $canReadDocumentMetadata = Gate::allows(PermissionName::ContractDocumentsDownload->value)
            || Gate::allows(PermissionName::ContractDocumentsDelete->value);
        $relations = [
            'company:id,name,status',
            'orders' => fn ($query) => $query
                ->with('serviceType')
                ->withExists('invoiceLines'),
            'subscriptions' => fn ($query) => $query
                ->with('serviceType')
                ->withExists('invoiceLines'),
        ];

        if ($canReadDocumentMetadata) {
            $relations['documents'] = function ($query) {
                $query
                    ->select([
                        'id',
                        'contract_id',
                        'document_type',
                        'original_name',
                        'file_size',
                        'comment',
                        'created_at',
                    ])
                    ->latest();
            };
        }

        $contract->load($relations);

        $companyContext = $this->safeCompanyContext($request, $contract->company);
        $contractCanBeDeleted = Gate::allows('delete', $contract)
            && $canReadDocumentMetadata
            && $deleteContract->canDelete($contract);

        return view(
            'contracts.show',
            compact(
                'contract',
                'companyContext',
                'contractCanBeDeleted',
                'canUploadDocuments',
                'canReadDocumentMetadata'
            )
        );
    }

    public function edit(Request $request, Contract $contract)
    {
        Gate::authorize('update', $contract);

        $contract->loadMissing('company:id,name');
        $company = $contract->company;
        $returnContext = $this->editReturnContext($request, $contract);

        return view(
            'contracts.edit',
            compact('contract', 'company', 'returnContext')
        );
    }

    public function update(Request $request, Contract $contract, UpdateContract $updateContract)
    {
        Gate::authorize('update', $contract);

        $validated = $request->validate([
            'contract_number' => 'required|string|max:50|unique:contracts,contract_number,'.$contract->id,
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => 'required|in:active,terminated',
            'signed_document' => 'prohibited',
            'comment' => 'nullable|string',
        ]);

        $contract = $updateContract->handle($contract, $validated, $request->user());

        return $this->mutationRedirect($request, $contract)
            ->with('success', __('contracts.flash.updated'));
    }

    private function editReturnContext(Request $request, Contract $contract): array
    {
        if (Gate::allows('view', $contract)) {
            return $this->contractEditReturnContext($request, $contract);
        }

        if (Gate::allows('view', $contract->company)) {
            return [
                'url' => route('companies.show', $contract->company),
                'route' => 'companies.show',
                'route_parameters' => ['company' => $contract->company],
                'hidden' => [],
            ];
        }

        return [
            'url' => $this->landingUrl(),
            'route' => null,
            'route_parameters' => [],
            'hidden' => [],
        ];
    }

    private function contractEditReturnContext(Request $request, Contract $contract): array
    {
        if ($request->input('edit_origin') === 'index') {
            $parameters = $this->contractIndexReturnParameters($request);

            return [
                'url' => route('contracts.index', $parameters),
                'route' => 'contracts.index',
                'route_parameters' => $parameters,
                'hidden' => ['edit_origin' => 'index', ...$parameters],
            ];
        }

        $companyContext = CompanyPageContext::resolve($request, $contract->company, 'contracts');
        $parameters = ['contract' => $contract, ...$companyContext['query']];

        return [
            'url' => route('contracts.show', $parameters),
            'route' => 'contracts.show',
            'route_parameters' => $parameters,
            'hidden' => ['edit_origin' => 'show', ...$companyContext['query']],
        ];
    }

    private function contractIndexReturnParameters(Request $request): array
    {
        $parameters = [];
        $search = mb_substr(trim((string) $request->input('search', '')), 0, 255);

        if ($search !== '') {
            $parameters['search'] = $search;
        }
        if (in_array($request->input('status'), ['active', 'terminated'], true)) {
            $parameters['status'] = $request->input('status');
        }

        $companyId = filter_var($request->input('company_id'), FILTER_VALIDATE_INT);
        if ($companyId !== false && $companyId > 0) {
            $parameters['company_id'] = $companyId;
        }
        if (in_array($request->input('sort_by'), ['start_date', 'end_date'], true)) {
            $parameters['sort_by'] = $request->input('sort_by');
        }
        if (in_array($request->input('sort_direction'), ['asc', 'desc'], true)) {
            $parameters['sort_direction'] = $request->input('sort_direction');
        }

        $page = filter_var($request->input('page'), FILTER_VALIDATE_INT);
        if ($page !== false && $page > 0) {
            $parameters['page'] = $page;
        }

        return $parameters;
    }

    public function destroy(Request $request, Contract $contract, DeleteContract $deleteContract)
    {
        Gate::authorize('delete', $contract);

        $contract->loadMissing('company:id,name');
        $company = $contract->company;

        try {
            $deleteContract->handle($contract, $request->user());
        } catch (ContractDeletionException $exception) {
            return $this->failedDeletionRedirect($contract, $company)
                ->with('error', __('contracts.errors.delete_dependencies'));
        }

        return $this->deletedRedirect($company)
            ->with('success', __('contracts.flash.deleted'));
    }

    private function safeCompanyContext(Request $request, Company $company): array
    {
        if (! Gate::allows('view', $company)) {
            return [
                'active' => false,
                'company_url' => null,
                'label' => null,
                'query' => [],
            ];
        }

        return CompanyPageContext::resolve($request, $company, 'contracts');
    }

    private function createBackUrl(?Company $company, ?array $companyContext): string
    {
        if ($company !== null && Gate::allows('view', $company)) {
            return ($companyContext['active'] ?? false)
                ? $companyContext['company_url']
                : route('companies.show', $company);
        }

        if (Gate::allows('viewAny', Contract::class)) {
            return route('contracts.index');
        }

        return $this->landingUrl();
    }

    private function mutationRedirect(Request $request, Contract $contract)
    {
        $contract->loadMissing('company:id,name');

        if (Gate::allows('view', $contract)) {
            $companyContext = $this->safeCompanyContext($request, $contract->company);

            return redirect()->route('contracts.show', [
                'contract' => $contract,
                ...$companyContext['query'],
            ]);
        }

        if (Gate::allows('view', $contract->company)) {
            return redirect()->route('companies.show', $contract->company);
        }

        return redirect()->to($this->landingUrl());
    }

    private function failedDeletionRedirect(Contract $contract, Company $company)
    {
        if (Gate::allows('view', $contract)) {
            return redirect()->route('contracts.show', $contract);
        }

        if (Gate::allows('view', $company)) {
            return redirect()->route('companies.show', $company);
        }

        return redirect()->to($this->landingUrl());
    }

    private function deletedRedirect(Company $company)
    {
        if (Gate::allows('viewAny', Contract::class)) {
            return redirect()->route('contracts.index');
        }

        if (Gate::allows('view', $company)) {
            return redirect()->route('companies.show', $company);
        }

        return redirect()->to($this->landingUrl());
    }

    private function landingUrl(): string
    {
        return app(AuthorizedLandingPage::class)->url(auth()->user());
    }
}
