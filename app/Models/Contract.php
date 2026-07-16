<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    protected $fillable = [
        'company_id',
        'contract_number',
        'start_date',
        'end_date',
        'status',
        'comment',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    /**
     * Фактический статус договора.
     *
     * terminated — договор расторгнут вручную;
     * expired — срок договора истёк;
     * active — договор действует.
     */
    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === 'terminated') {
            return 'terminated';
        }

        if ($this->end_date && $this->end_date->lt(today())) {
            return 'expired';
        }

        return 'active';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
