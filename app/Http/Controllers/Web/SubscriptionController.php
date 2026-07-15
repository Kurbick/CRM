<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Subscription;
use App\Models\ServiceType;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function create(Contract $contract)
    {
        $serviceTypes = ServiceType::where('type', 'subscription')->orderBy('name')->get();
        return view('subscriptions.create', compact('contract', 'serviceTypes'));
    }

    public function store(Request $request, Contract $contract)
    {
        $request->validate([
            'service_type_id'   => 'required|exists:service_types,id',
            'start_date'        => 'required|date',
            'next_billing_date' => 'required|date|after_or_equal:start_date',
            'billing_period'    => 'required|in:monthly,quarterly,semiannual,annual',
            'amount'            => 'required|numeric|min:0',
            'payment_terms'     => 'nullable|integer|min:1|max:365',
            'status'            => 'required|in:active,suspended,completed,cancelled',
            'comment'           => 'nullable|string',
        ]);

        $contract->subscriptions()->create($request->all());

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Подписка успешно добавлена.');
    }

    public function edit(Subscription $subscription)
    {
        $contract = $subscription->contract;
        $serviceTypes = ServiceType::where('type', 'subscription')->orderBy('name')->get();
        return view('subscriptions.edit', compact('subscription', 'contract', 'serviceTypes'));
    }

    public function update(Request $request, Subscription $subscription)
    {
        $request->validate([
            'service_type_id'   => 'required|exists:service_types,id',
            'start_date'        => 'required|date',
            'next_billing_date' => 'required|date|after_or_equal:start_date',
            'billing_period'    => 'required|in:monthly,quarterly,semiannual,annual',
            'amount'            => 'required|numeric|min:0',
            'payment_terms'     => 'nullable|integer|min:1|max:365',
            'status'            => 'required|in:active,suspended,completed,cancelled',
            'comment'           => 'nullable|string',
        ]);

        $subscription->update($request->all());

        return redirect()->route('contracts.show', $subscription->contract)
            ->with('success', 'Подписка обновлена.');
    }

    public function destroy(Subscription $subscription)
    {
        $contract = $subscription->contract;
        try {
            $subscription->delete();
            return redirect()->route('contracts.show', $contract)
                ->with('success', 'Подписка удалена.');
        } catch (\Exception $e) {
            return redirect()->route('contracts.show', $contract)
                ->with('error', 'Невозможно удалить — подписка включена в инвойс.');
        }
    }
}