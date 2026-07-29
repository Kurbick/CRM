<?php

namespace App\Http\Controllers\Web;

use App\Actions\Orders\DeleteOrder;
use App\Exceptions\OrderDeletionException;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Order;
use App\Models\ServiceType;
use App\Services\InvoiceDueDateSynchronizer;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function create(Contract $contract)
    {
        Gate::authorize('create', [Order::class, $contract]);

        $contract->loadMissing('company:id,name');
        $backUrl = $this->backUrl($contract);

        return view('orders.create', compact('contract', 'backUrl'));
    }

    public function store(Request $request, Contract $contract)
    {
        Gate::authorize('create', [Order::class, $contract]);

        $validated = $request->validate([
            'service_name' => 'required|string|max:255',
            'order_date' => 'required|date',
            'price' => 'required|numeric|min:0',
            'payment_terms' => 'required|integer|min:0|max:3650',
            'status' => 'required|in:in_progress,completed,cancelled',
            'comment' => 'nullable|string',
        ], [
            'payment_terms.required' => 'Укажите срок оплаты в днях.',
            'payment_terms.integer' => 'Срок оплаты должен быть целым числом дней.',
        ]);

        $serviceType = ServiceType::firstOrCreate(
            [
                'name' => trim($validated['service_name']),
                'type' => 'one_time',
            ],
            [
                'base_price' => $validated['price'],
            ]
        );

        unset($validated['service_name']);

        $validated['service_type_id'] = $serviceType->id;

        $contract->orders()->create($validated);

        return $this->mutationRedirect($contract)
            ->with('success', 'Разовая услуга успешно добавлена.');
    }

    public function edit(Order $order)
    {
        Gate::authorize('update', $order);

        $contract = $order->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();
        $contract->loadMissing('company:id,name');
        $order->loadMissing('serviceType:id,name');
        $backUrl = $this->backUrl($contract);

        return view('orders.edit', compact('order', 'contract', 'backUrl'));
    }

    public function update(
        Request $request,
        Order $order,
        InvoiceDueDateSynchronizer $dueDateSynchronizer
    ) {
        Gate::authorize('update', $order);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'order_date' => 'required|date',
            'price' => 'required|numeric|min:0',
            'payment_terms' => 'required|integer|min:0|max:3650',
            'status' => 'required|in:in_progress,completed,cancelled',
            'comment' => 'nullable|string',
        ], [
            'payment_terms.required' => 'Укажите срок оплаты в днях.',
            'payment_terms.integer' => 'Срок оплаты должен быть целым числом дней.',
        ]);

        $validated['title'] = trim($validated['title']);
        $validated['service_type_id'] = null;

        DB::transaction(function () use ($order, $validated, $dueDateSynchronizer): void {
            $order->update($validated);
            $dueDateSynchronizer->synchronizeForOrder($order);
        });

        $contract = $order->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();

        return $this->mutationRedirect($contract)
            ->with('success', 'Разовая услуга обновлена.');
    }

    public function destroy(Order $order, DeleteOrder $deleteOrder)
    {
        Gate::authorize('delete', $order);

        $contract = $order->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();

        try {
            $deleteOrder->handle($order);
        } catch (OrderDeletionException $exception) {
            return $this->mutationRedirect($contract)
                ->with('error', $exception->getMessage());
        }

        return $this->mutationRedirect($contract)
            ->with('success', 'Разовая услуга удалена.');
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

        return $this->landingUrl();
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

        return redirect()->to($this->landingUrl());
    }

    private function landingUrl(): string
    {
        return app(AuthorizedLandingPage::class)->url(auth()->user());
    }
}
