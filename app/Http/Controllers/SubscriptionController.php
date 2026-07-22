<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Subscription;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Services\InvoiceDueDateSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function index(Contract $contract): JsonResponse
    {
        $subscriptions = $contract->subscriptions()
            ->with('serviceType')
            ->get();

        return response()->json($subscriptions);
    }

    public function store(StoreSubscriptionRequest $request, Contract $contract): JsonResponse
    {
        $subscription = $contract->subscriptions()->create($request->validated());

        return response()->json($subscription->load('serviceType'), 201);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load(['contract.company', 'serviceType', 'invoiceLines']);

        return response()->json($subscription);
    }

    public function update(
        UpdateSubscriptionRequest $request,
        Subscription $subscription,
        InvoiceDueDateSynchronizer $dueDateSynchronizer
    ): JsonResponse
    {
        DB::transaction(function () use ($request, $subscription, $dueDateSynchronizer): void {
            $subscription->update($request->validated());
            $dueDateSynchronizer->synchronizeForSubscription($subscription);
        });

        return response()->json($subscription);
    }

    public function destroy(Subscription $subscription): JsonResponse
    {
        try {
            $subscription->delete();
            return response()->json(['message' => 'Подписка удалена'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Невозможно удалить — подписка включена в инвойс'
            ], 409);
        }
    }
}
