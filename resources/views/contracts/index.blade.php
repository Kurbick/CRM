@extends('layouts.app')
@section('title', 'Договора')
@section('content')

<div class="mb-6 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Договора</h1>
        <p class="text-sm text-gray-500 mt-1">Все контракты с клиентами</p>
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left">
                    <th class="px-6 py-3.5 text-xs font-semibold text-gray-500 uppercase">Номер договора</th>
                    <th class="px-6 py-3.5 text-xs font-semibold text-gray-500 uppercase">Компания</th>
                    <th class="px-6 py-3.5 text-xs font-semibold text-gray-500 uppercase">Дата начала</th>
                    <th class="px-6 py-3.5 text-xs font-semibold text-gray-500 uppercase">Дата окончания</th>
                    <th class="px-6 py-3.5 text-xs font-semibold text-gray-500 uppercase">Статус</th>
                    <th class="px-6 py-3.5 text-xs font-semibold text-gray-500 uppercase"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-gray-700">
                @forelse($contracts as $contract)
                    <tr class="hover:bg-gray-50/50 transition">
                        <td class="px-6 py-4 font-mono font-medium text-gray-900">
                            {{ $contract->contract_number }}
                        </td>
                        <td class="px-6 py-4">
                            <a href="{{ route('companies.show', $contract->company) }}"
                               class="text-blue-600 hover:underline font-medium">
                                {{ $contract->company->name }}
                            </a>
                        </td>
                        <td class="px-6 py-4 text-gray-600">{{ $contract->start_date }}</td>
                        <td class="px-6 py-4 text-gray-400">{{ $contract->end_date ?? 'Бессрочный' }}</td>
                        <td class="px-6 py-4">
                            @include('partials.badge', ['status' => $contract->status])
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('contracts.show', $contract) }}"
                               class="text-blue-600 hover:text-blue-800 text-sm font-semibold">
                                Открыть →
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                            Договоров пока нет. Добавьте договор через страницу компании.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($contracts->hasPages())
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50">
            {{ $contracts->links() }}
        </div>
    @endif
</div>

@endsection