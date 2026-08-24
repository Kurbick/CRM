<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CompanyActivityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'actor_user_id',
        'event_type',
        'category',
        'visibility_scope',
        'subject_type',
        'subject_id',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Company activity events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Company activity events are append-only.');
        });
    }
}
