<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        $query = Contract::query()
            ->with('company');

        // Поиск по номеру договора или названию компании
        if ($request->filled('search')) {
            $search = trim($request->input('search'));

            $query->where(function ($query) use ($search) {
                $query
                    ->where('contract_number', 'like', "%{$search}%")
                    ->orWhereHas('company', function ($companyQuery) use ($search) {
                        $companyQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Фильтр по фактическому статусу договора
        if ($request->filled('status')) {
            switch ($request->input('status')) {
                case 'active':
                    $query
                        ->where('status', 'active')
                        ->where(function ($query) {
                            $query
                                ->whereNull('end_date')
                                ->orWhereDate('end_date', '>=', today());
                        });
                    break;

                case 'expired':
                    $query
                        ->where('status', 'active')
                        ->whereNotNull('end_date')
                        ->whereDate('end_date', '<', today());
                    break;

                case 'terminated':
                    $query->where('status', 'terminated');
                    break;
            }
        }

        // Фильтр по компании
        if ($request->filled('company_id')) {
            $query->where(
                'company_id',
                $request->integer('company_id')
            );
        }

        // Сортировка по дате создания
        if ($request->input('sort') === 'oldest') {
            $query->orderBy('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $contracts = $query
            ->paginate(15)
            ->withQueryString();

        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return view(
            'contracts.index',
            compact('contracts', 'companies')
        );
    }

    public function create(?Company $company = null)
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get();

        return view(
            'contracts.create',
            compact('company', 'companies')
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

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', 'Договор успешно добавлен.');
    }

    public function show(Contract $contract)
    {
        $contract->load([
            'company',
            'orders.serviceType',
            'subscriptions.serviceType',
            'documents' => function ($query) {
                $query->latest();
            },
        ]);

        return view(
            'contracts.show',
            compact('contract')
        );
    }

    public function edit(Contract $contract)
    {
        $company = $contract->company;

        return view(
            'contracts.edit',
            compact('contract', 'company')
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

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', 'Договор обновлён.');
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
