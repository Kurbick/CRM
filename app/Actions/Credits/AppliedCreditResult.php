<?php

namespace App\Actions\Credits;

final readonly class AppliedCreditResult
{
    public const NO_CREDIT_BALANCE = 'no_credit_balance';

    public const ZERO_CREDIT = 'zero_credit';

    public const FULLY_RESERVED = 'fully_reserved';

    public function __construct(
        public bool $applied,
        public int $appliedAmountMinor,
        public ?int $paymentId,
        public ?int $entryId,
        public ?int $creditBalanceId,
        public ?string $noOpReason,
    ) {}

    public static function applied(
        int $appliedAmountMinor,
        int $paymentId,
        int $entryId,
        int $creditBalanceId,
    ): self {
        return new self(
            applied: true,
            appliedAmountMinor: $appliedAmountMinor,
            paymentId: $paymentId,
            entryId: $entryId,
            creditBalanceId: $creditBalanceId,
            noOpReason: null,
        );
    }

    public static function noOp(string $reason, ?int $creditBalanceId = null): self
    {
        return new self(
            applied: false,
            appliedAmountMinor: 0,
            paymentId: null,
            entryId: null,
            creditBalanceId: $creditBalanceId,
            noOpReason: $reason,
        );
    }
}
