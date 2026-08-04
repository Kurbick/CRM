<?php

namespace App\Exceptions\Invoices;

use DomainException;

final class InvoiceDeletionException extends DomainException
{
    public static function notDraft(): self
    {
        return new self('Удалить можно только черновик инвойса.');
    }

    public static function paymentExists(): self
    {
        return new self('Нельзя удалить инвойс, по которому зарегистрирован платёж.');
    }

    public static function allocationExists(): self
    {
        return new self('Нельзя удалить инвойс, связанный с финансовыми операциями.');
    }

    public static function creditEntryExists(): self
    {
        return new self('Нельзя удалить инвойс, связанный с финансовыми операциями.');
    }

    public static function laterSubscriptionOccurrenceExists(): self
    {
        return new self('Нельзя удалить инвойс: по подписке уже существует более поздний инвойс.');
    }

    public static function inconsistentSubscriptionOccurrence(): self
    {
        return new self('Нельзя безопасно восстановить график подписки для этого инвойса.');
    }
}
