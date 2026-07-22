<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Support\CompanyPageContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        $query = Contract::query()
            ->with('company');

        /*
     * Поиск по номеру договора
     * или названию компании.
     */
        if ($request->filled('search')) {
            $search = trim($request->input('search'));

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
        if ($request->filled('status')) {
            switch ($request->input('status')) {
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
        if ($request->filled('company_id')) {
            $query->where(
                'company_id',
                $request->integer('company_id')
            );
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
            !in_array(
                $sortBy,
                $allowedSortColumns,
                true
            )
        ) {
            $sortBy = 'start_date';
        }

        if (
            !in_array(
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

        $contracts = $query
            ->paginate(15)
            ->withQueryString();

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
                'contractEditContext'
            )
        );
    }

    public function create(Request $request, ?Company $company = null)
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get();

        $companyContext = $company ? CompanyPageContext::resolve($request, $company, 'contracts') : null;

        return view(
            'contracts.create',
            compact('company', 'companies', 'companyContext')
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id'      => 'required|exists:companies,id',
            'contract_number' => 'required|string|max:50|unique:contracts,contract_number',
            'start_date'      => 'required|date',
            'end_date'        => 'nullable|date|after:start_date',
            'status'          => 'required|in:active,terminated',
            'comment'         => 'nullable|string',
        ]);

        $contract = Contract::create($validated);
        $companyContext = CompanyPageContext::resolve($request, $contract->company, 'contracts');

        return redirect()
            ->route('contracts.show', ['contract' => $contract, ...$companyContext['query']])
            ->with('success', 'Договор успешно добавлен.');
    }

    public function show(Request $request, Contract $contract)
    {
        $contract->load([
            'company',
            'orders.serviceType',
            'subscriptions.serviceType',
            'documents' => function ($query) {
                $query->latest();
            },
        ]);

        $companyContext = CompanyPageContext::resolve($request, $contract->company, 'contracts');

        return view(
            'contracts.show',
            compact('contract', 'companyContext')
        );
    }

    public function edit(Request $request, Contract $contract)
    {
        $company = $contract->company;
        $returnContext = $this->contractEditReturnContext($request, $contract);

        return view(
            'contracts.edit',
            compact('contract', 'company', 'returnContext')
        );
    }

    public function update(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'contract_number' => 'required|string|max:50|unique:contracts,contract_number,' . $contract->id,
            'start_date'      => 'required|date',
            'end_date'        => 'nullable|date|after:start_date',
            'status'          => 'required|in:active,terminated',
            'comment'         => 'nullable|string',
        ]);

        $contract->update($validated);
        $returnContext = $this->contractEditReturnContext($request, $contract);

        return redirect()
            ->route($returnContext['route'], $returnContext['route_parameters'])
            ->with('success', 'Договор обновлён.');
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

    public function destroy(Contract $contract)
    {
        // Не удаляем договор, пока у него есть предметы.
        if (
            $contract->orders()->exists() ||
            $contract->subscriptions()->exists()
        ) {
            return redirect()
                ->route('contracts.show', $contract)
                ->with(
                    'error',
                    'Невозможно удалить договор — сначала удалите связанные заказы и подписки.'
                );
        }

        // Сохраняем пути до удаления записей из базы.
        $documentPaths = $contract->documents()
            ->pluck('file_path')
            ->all();

        try {
            /*
             * После удаления договора записи contract_documents
             * удалятся автоматически благодаря cascadeOnDelete().
             */
            $contract->delete();

            // Удаляем физические файлы только после успешного удаления договора.
            if (!empty($documentPaths)) {
                Storage::disk('local')->delete($documentPaths);
            }

            return redirect()
                ->route('contracts.index')
                ->with('success', 'Договор удалён.');
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('contracts.show', $contract)
                ->with(
                    'error',
                    'Не удалось удалить договор. Попробуйте ещё раз.'
                );
        }
    }
}
