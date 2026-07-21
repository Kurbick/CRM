<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment allocation must link a payment and an invoice line belonging to
 * the same invoice. This business invariant will be enforced transactionally
 * by the allocation-writing service; ordinary foreign keys cannot express it.
 */
class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id',
        'invoice_line_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
