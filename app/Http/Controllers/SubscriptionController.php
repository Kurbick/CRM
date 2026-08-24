<?php

namespace App\Http\Controllers;

use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\DeleteSubscription;
use App\Actions\Subscriptions\UpdateSubscription;
use App\Exceptions\SubscriptionDeletionException;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Models\Contract;
use App\Models\ServiceType;
use App\Models\Subscription;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    private const COMPACT_FIELDS = [
        'id',
        'contract_id',
        'service_type_id',
        'title',
        'start_date',
        'billing_period',
        'custom_interval_value',
        'custom_interval_unit',
        'amount',
        'payment_terms',
        'status',
        'next_billing_date',
        'created_at',
        'updated_at',
    ];

    public function index(Contract $contract): JsonResponse
    {
        Gate::authorize('view', $contract);

        $subscriptions = $contract->subscriptions()
            ->select(self::COMPACT_FIELDS)
            ->with('serviceType:id,name,type')
            ->orderBy('id')
            ->get()
            ->map(fn (Subscription $subscription): array => $this->compactProjection(
                $subscription,
                $subscription->serviceType
            ));

        return response()->json($subscriptions);
    }

    public function store(
        StoreSubscriptionRequest $request,
        Contract $contract,
        CreateSubscription $createSubscription
    ): JsonResponse {
        $subscription = $createSubscription->handle($contract, $request->validated(), $request->user());
        $subscription->refresh();

        return response()->json($this->detailProjection(
            $subscription,
            $contract,
            $this->serviceTypeSummaryModel($subscription)
        ), 201);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        Gate::authorize('view', $subscription);

        return response()->json($this->detailProjection(
            $subscription,
            $this->contractSummaryModel($subscription),
            $this->serviceTypeSummaryModel($subscription)
        ));
    }

    public function update(
        UpdateSubscriptionRequest $request,
        Subscription $subscription,
        UpdateSubscription $updateSubscription
    ): JsonResponse {
        $subscription = $updateSubscription->handle($subscription, $request->validated(), $request->user());

        return response()->json($this->detailProjection(
            $subscription,
            $this->contractSummaryModel($subscription),
            $this->serviceTypeSummaryModel($subscription)
        ));
    }

    public function destroy(Request $request, Subscription $subscription, DeleteSubscription $deleteSubscription): JsonResponse
    {
        Gate::authorize('delete', $subscription);

        try {
            $deleteSubscription->handle($subscription, $request->user());

            return response()->json(['message' => 'Подписка удалена'], 200);
        } catch (SubscriptionDeletionException) {
            return response()->json([
                'message' => 'Невозможно удалить — подписка включена в инвойс',
            ], 409);
        }
    }

    /** @return array<string, mixed> */
    private function compactProjection(
        Subscription $subscription,
        ?ServiceType $serviceType
    ): array {
        return [
            'id' => $subscription->id,
            'contract_id' => $subscription->contract_id,
            'service_type_id' => $subscription->service_type_id,
            'title' => $subscription->title,
            'start_date' => $this->dateValue($subscription->start_date),
            'billing_period' => $subscription->billing_period,
            'custom_interval_value' => $subscription->custom_interval_value,
            'custom_interval_unit' => $subscription->custom_interval_unit,
            'amount' => $this->decimalValue($subscription->amount),
            'payment_terms' => (int) $subscription->payment_terms,
            'status' => $subscription->status,
            'next_billing_date' => $this->dateValue($subscription->next_billing_date),
            'created_at' => $subscription->created_at?->toJSON(),
            'updated_at' => $subscription->updated_at?->toJSON(),
            'service_type' => $this->serviceTypeProjection($serviceType),
        ];
    }

    /** @return array<string, mixed> */
    private function detailProjection(
        Subscription $subscription,
        Contract $contract,
        ?ServiceType $serviceType
    ): array {
        return [
            'id' => $subscription->id,
            'contract_id' => $subscription->contract_id,
            'service_type_id' => $subscription->service_type_id,
            'title' => $subscription->title,
            'start_date' => $this->dateValue($subscription->start_date),
            'billing_period' => $subscription->billing_period,
            'custom_interval_value' => $subscription->custom_interval_value,
            'custom_interval_unit' => $subscription->custom_interval_unit,
            'amount' => $this->decimalValue($subscription->amount),
            'payment_terms' => (int) $subscription->payment_terms,
            'status' => $subscription->status,
            'next_billing_date' => $this->dateValue($subscription->next_billing_date),
            'comment' => $subscription->comment,
            'created_at' => $subscription->created_at?->toJSON(),
            'updated_at' => $subscription->updated_at?->toJSON(),
            'contract' => [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ],
            'service_type' => $this->serviceTypeProjection($serviceType),
        ];
    }

    /** @return array{id: int, name: string, type: string}|null */
    private function serviceTypeProjection(?ServiceType $serviceType): ?array
    {
        if ($serviceType === null) {
            return null;
        }

        return [
            'id' => $serviceType->id,
            'name' => $serviceType->name,
            'type' => $serviceType->type,
        ];
    }

    private function contractSummaryModel(Subscription $subscription): Contract
    {
        return $subscription->contract()
            ->select(['contracts.id', 'contracts.company_id', 'contracts.contract_number'])
            ->firstOrFail();
    }

    private function serviceTypeSummaryModel(Subscription $subscription): ?ServiceType
    {
        if ($subscription->service_type_id === null) {
            return null;
        }

        return $subscription->serviceType()
            ->select(['service_types.id', 'service_types.name', 'service_types.type'])
            ->first();
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : (string) $value;
    }

    private function decimalValue(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
