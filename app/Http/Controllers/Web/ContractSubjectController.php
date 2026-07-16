<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;

class ContractSubjectController extends Controller
{
    public function create(Contract $contract)
    {
        $contract->load('company');

        return view('contract-subjects.create', compact('contract'));
    }

    public function store(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'subject_type' => 'required|in:one_time,subscription',

            'title' => 'required|string|max:255',

            'order_date' => 'nullable|required_if:subject_type,one_time|date',
            'deadline' => 'nullable|date|after_or_equal:order_date',
            'price' => 'nullable|required_if:subject_type,one_time|numeric|min:0',

            'start_date' => 'nullable|required_if:subject_type,subscription|date',
            'billing_period' => 'nullable|required_if:subject_type,subscription|in:monthly,quarterly,semiannual,annual,custom',
            'billing_period_custom' => 'nullable|required_if:billing_period,custom|string|max:255',
            'amount' => 'nullable|required_if:subject_type,subscription|numeric|min:0',

            'payment_terms' => 'nullable|integer|min:1|max:365',
            'comment' => 'nullable|string',
        ]);

        if ($validated['subject_type'] === 'one_time') {
            $contract->orders()->create([
                'title' => trim($validated['title']),
                'service_type_id' => null,
                'order_date' => $validated['order_date'],
                'deadline' => $validated['deadline'] ?? null,
                'price' => $validated['price'],
                'payment_terms' => $validated['payment_terms'] ?? null,
                'status' => 'in_progress',
                'comment' => $validated['comment'] ?? null,
            ]);
        } else {
            $contract->subscriptions()->create([
                'title' => trim($validated['title']),
                'service_type_id' => null,
                'start_date' => $validated['start_date'],
                'billing_period' => $validated['billing_period'],
                'billing_period_custom' => $validated['billing_period'] === 'custom'
                    ? ($validated['billing_period_custom'] ?? null)
                    : null,
                'amount' => $validated['amount'],
                'payment_terms' => $validated['payment_terms'] ?? null,
                'next_billing_date' => $validated['start_date'],
                'status' => 'active',
                'comment' => $validated['comment'] ?? null,
            ]);
        }

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', 'Предмет договора успешно добавлен.');
    }
}