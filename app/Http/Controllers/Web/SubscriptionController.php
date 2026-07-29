<?php

namespace App\Http\Controllers\Web;

use App\Actions\Subscriptions\DeleteSubscription;
use App\Exceptions\SubscriptionDeletionException;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ServiceType;
use App\Models\Subscription;
use App\Services\InvoiceDueDateSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    public function create(Contract $contract)
    {
        Gate::authorize('create', [Subscription::class, $contract]);

        $contract->loadMissing('company:id,name');
        $backUrl = $this->backUrl($contract);

        return view('subscriptions.create', compact('contract', 'backUrl'));
    }

    public function store(Request $request, Contract $contract)
    {
        Gate::authorize('create', [Subscription::class, $contract]);

        $validated = $request->validate([
            'service_name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'billing_period' => 'required|in:monthly,quarterly,semiannual,annual,custom',
            'billing_period_custom' => 'nullable|required_if:billing_period,custom|string|max:255',
            'amount' => 'required|numeric|min:0',
            'payment_terms' => 'required|integer|min:1|max:365',
            'status' => 'required|in:active,suspended,completed,cancelled',
            'comment' => 'nullable|string',
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

        $validated['next_billing_date'] = $validated['start_date'];
        $validated['service_type_id'] = $serviceType->id;

        $contract->subscriptions()->create($validated);

        return $this->mutationRedirect($contract)
            ->with('success', 'Подписка успешно добавлена.');
    }

    public function edit(Subscription $subscription)
    {
        Gate::authorize('update', $subscription);

        $contract = $subscription->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();
        $contract->loadMissing('company:id,name');
        $subscription->loadMissing('serviceType:id,name');
        $backUrl = $this->backUrl($contract);

        return view('subscriptions.edit', compact('subscription', 'contract', 'backUrl'));
    }

    public function update(
        Request $request,
        Subscription $subscription,
        InvoiceDueDateSynchronizer $dueDateSynchronizer
    ) {
        Gate::authorize('update', $subscription);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'billing_period' => 'required|in:monthly,quarterly,semiannual,annual,custom',
            'billing_period_custom' => 'nullable|required_if:billing_period,custom|string|max:255',
            'amount' => 'required|numeric|min:0',
            'payment_terms' => 'required|integer|min:1|max:365',
            'status' => 'required|in:active,suspended,completed,cancelled',
            'comment' => 'nullable|string',
        ]);

        $validated['title'] = trim($validated['title']);
        $validated['service_type_id'] = null;
        $validated['next_billing_date'] = $validated['start_date'];

        if ($validated['billing_period'] !== 'custom') {
            $validated['billing_period_custom'] = null;
        }

        DB::transaction(function () use ($subscription, $validated, $dueDateSynchronizer): void {
            $subscription->update($validated);
            $dueDateSynchronizer->synchronizeForSubscription($subscription);
        });

        $contract = $subscription->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();

        return $this->mutationRedirect($contract)
            ->with('success', 'Подписка обновлена.');
    }

    public function destroy(Subscription $subscription, DeleteSubscription $deleteSubscription)
    {
        Gate::authorize('delete', $subscription);

        $contract = $subscription->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();

        try {
            $deleteSubscription->handle($subscription);
        } catch (SubscriptionDeletionException $exception) {
            return $this->mutationRedirect($contract)
                ->with('error', $exception->getMessage());
        }

        return $this->mutationRedirect($contract)
            ->with('success', 'Подписка удалена.');
    }

    private function backUrl(Contract $contract): string
    {
        if (Gate::allows('view', $contract)) {
            return route('contracts.show', $contract);
        }

        $contract->loadMissing('company:id,name');

        if (Gate::allows('view', $contract->company)) {
            return route('companies.show', $contract->company);
        }

        return route('dashboard');
    }

    private function mutationRedirect(Contract $contract)
    {
        if (Gate::allows('view', $contract)) {
            return redirect()->route('contracts.show', $contract);
        }

        $contract->loadMissing('company:id,name');

        if (Gate::allows('view', $contract->company)) {
            return redirect()->route('companies.show', $contract->company);
        }

        return redirect()->route('dashboard');
    }
}
