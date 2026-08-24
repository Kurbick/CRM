<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyActivityEvent;
use App\Models\CompanyContact;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivityVisibilityScope;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class CompanyActivityRecorder
{
    public function record(
        Company $company,
        CompanyActivityEventType|string $eventType,
        CompanyActivityCategory|string $category,
        CompanyActivityVisibilityScope|string $visibilityScope,
        ?Model $subject = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?DateTimeInterface $occurredAt = null,
    ): CompanyActivityEvent {
        $eventType = $eventType instanceof CompanyActivityEventType ? $eventType->value : $eventType;
        $category = $category instanceof CompanyActivityCategory ? $category->value : $category;
        $visibilityScope = $visibilityScope instanceof CompanyActivityVisibilityScope
            ? $visibilityScope->value
            : $visibilityScope;

        return CompanyActivityEvent::query()->create([
            'company_id' => $company->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'event_type' => $eventType,
            'category' => $category,
            'visibility_scope' => $visibilityScope,
            'subject_type' => $this->subjectType($subject),
            'subject_id' => $subject?->getKey(),
            'occurred_at' => $occurredAt === null
                ? now()
                : Carbon::instance($occurredAt),
            'metadata' => $metadata,
        ]);
    }

    private function subjectType(?Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Company => 'company',
            $subject instanceof CompanyContact => 'contact',
            $subject instanceof Contract => 'contract',
            $subject instanceof ContractDocument => 'document',
            $subject instanceof Invoice => 'invoice',
            $subject instanceof Payment => 'payment',
            $subject instanceof Order, $subject instanceof Subscription => 'contract_subject',
            $subject === null => null,
            default => throw new \InvalidArgumentException('Unsupported activity subject model.'),
        };
    }
}
