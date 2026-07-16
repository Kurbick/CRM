<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ServiceType;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function create(Contract $contract)
    {
        return view('subscriptions.create', compact('contract'));
    }

    public function store(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'service_name'          => 'required|string|max:255',
            'start_date'            => 'required|date',
            'billing_period'        => 'required|in:monthly,quarterly,semiannual,annual,custom',
            'billing_period_custom' => 'nullable|required_if:billing_period,custom|string|max:255',
            'amount'                => 'required|numeric|min:0',
            'payment_terms'         => 'nullable|integer|min:1|max:365',
            'status'                => 'required|in:active,suspended,completed,cancelled',
            'comment'               => 'nullable|string',
        ]);

        $serviceType = ServiceType::firstOrCreate(
            [
                'name' => trim($validated['service_name']),
                'type' => 'subscription',
            ],
            [
                'base_price' => $validated['amount'],
            ]
        );

        unset($validated['service_name']);

        /*
         * Поле пока существует в базе и является обязательным.
         * Пользователь его больше не заполняет.
         */
        $validated['next_billing_date'] = $validated['start_date'];
        $validated['service_type_id'] = $serviceType->id;

        $contract->subscriptions()->create($validated);

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', 'Подписка успешно добавлена.');
    }

    public function edit(Subscription $subscription)
    {
        $contract = $subscription->contract;

        return view('subscriptions.edit', compact('subscription', 'contract'));
    }

    public function update(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'billing_period' => 'required|in:monthly,quarterly,semiannual,annual,custom',
            'billing_period_custom' => 'nullable|required_if:billing_period,custom|string|max:255',
            'amount' => 'required|numeric|min:0',
            'payment_terms' => 'nullable|integer|min:1|max:365',
            'status' => 'required|in:active,suspended,completed,cancelled',
            'comment' => 'nullable|string',
        ]);

        $validated['title'] = trim($validated['title']);
        $validated['service_type_id'] = null;
        $validated['next_billing_date'] = $validated['start_date'];

        if ($validated['billing_period'] !== 'custom') {
            $validated['billing_period_custom'] = null;
        }

        $subscription->update($validated);

        return redirect()
            ->route('contracts.show', $subscription->contract)
            ->with('success', 'Подписка обновлена.');
    }

    public function destroy(Subscription $subscription)
    {
        $contract = $subscription->contract;

        try {
            $subscription->delete();

            return redirect()
                ->route('contracts.show', $contract)
                ->with('success', 'Подписка удалена.');
        } catch (\Exception $e) {
            return redirect()
                ->route('contracts.show', $contract)
                ->with('error', 'Невозможно удалить — подписка включена в инвойс.');
        }
    }
}