<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subscription = $this->route('subscription');

        return $subscription instanceof Subscription
            && ($this->user()?->can('update', $subscription) ?? false);
    }

    public function rules(): array
    {
        return [
            'service_type_id' => [
                'sometimes',
                Rule::exists('service_types', 'id')->where('type', 'subscription'),
            ],
            'title' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'next_billing_date' => 'prohibited',
            'billing_period' => 'sometimes|in:monthly,quarterly,semiannual,annual,custom',
            'custom_interval_value' => 'sometimes|nullable|integer|min:1|max:3650',
            'custom_interval_unit' => 'sometimes|nullable|in:day,month,year',
            'amount' => 'sometimes|numeric|min:0',
            'payment_terms' => 'required|integer|min:1|max:365',
            'status' => 'nullable|in:active,suspended,completed,cancelled',
            'comment' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $subscription = $this->route('subscription');
            if (! $subscription instanceof Subscription) {
                return;
            }

            $effectivePeriod = $this->input('billing_period', $subscription->billing_period);
            $hasValue = $this->exists('custom_interval_value');
            $hasUnit = $this->exists('custom_interval_unit');

            if ($effectivePeriod !== 'custom') {
                if ($this->filled('custom_interval_value')) {
                    $validator->errors()->add(
                        'custom_interval_value',
                        'Свой интервал разрешён только для периода custom.'
                    );
                }
                if ($this->filled('custom_interval_unit')) {
                    $validator->errors()->add(
                        'custom_interval_unit',
                        'Свой интервал разрешён только для периода custom.'
                    );
                }

                return;
            }

            if ($hasValue xor $hasUnit) {
                $missingField = $hasValue ? 'custom_interval_unit' : 'custom_interval_value';
                $validator->errors()->add(
                    $missingField,
                    'Значение и единица своего интервала должны передаваться вместе.'
                );

                return;
            }

            $effectiveValue = $hasValue
                ? $this->input('custom_interval_value')
                : $subscription->custom_interval_value;
            $effectiveUnit = $hasUnit
                ? $this->input('custom_interval_unit')
                : $subscription->custom_interval_unit;

            if ($effectiveValue === null || $effectiveValue === '') {
                $validator->errors()->add('custom_interval_value', 'Укажите значение своего интервала.');
            }
            if ($effectiveUnit === null || $effectiveUnit === '') {
                $validator->errors()->add('custom_interval_unit', 'Укажите единицу своего интервала.');
            }
        });
    }
}
