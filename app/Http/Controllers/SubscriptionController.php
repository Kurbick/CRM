<?php

namespace App\Http\Controllers;

use App\Actions\Subscriptions\DeleteSubscription;
use App\Actions\Subscriptions\UpdateSubscription;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Models\Contract;
use App\Models\Subscription;
use App\Services\SubscriptionLifecycle;
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

    public function store(
        StoreSubscriptionRequest $request,
        Contract $contract,
        SubscriptionLifecycle $lifecycle
    ): JsonResponse {
        $subscription = DB::transaction(function () use ($request, $contract, $lifecycle): Subscription {
            $attributes = $lifecycle->normalizeInterval($request->validated());
            $subscription = $contract->subscriptions()->make($attributes);
            $subscription->next_billing_date = $attributes['start_date'];
            $subscription->save();

            return $subscription;
        });

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
        UpdateSubscription $updateSubscription
    ): JsonResponse {
        $subscription = $updateSubscription->handle($subscription, $request->validated());

        return response()->json($subscription);
    }

    public function destroy(Subscription $subscription, DeleteSubscription $deleteSubscription): JsonResponse
    {
        try {
            $deleteSubscription->handle($subscription);

            return response()->json(['message' => 'Подписка удалена'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Невозможно удалить — подписка включена в инвойс',
            ], 409);
        }
    }
}
