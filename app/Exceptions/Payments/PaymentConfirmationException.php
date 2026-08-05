<?php

namespace App\Exceptions\Payments;

use DomainException;

final class PaymentConfirmationException extends DomainException
{
    public static function invoiceMismatch(): self
    {
        return new self('Платёж не принадлежит заблокированному инвойсу.');
    }

    public static function paymentNotPending(): self
    {
        return new self('Подтвердить можно только платёж со статусом «Ожидает подтверждения».');
    }

    public static function invoiceStateNotAllowed(): self
    {
        return new self('Нельзя подтвердить платёж по черновику или отменённому инвойсу.');
    }

    public static function companyMismatch(): self
    {
        return new self('Компания платежа не совпадает с компанией инвойса.');
    }

    public static function invalidAmount(): self
    {
        return new self('Сумма платежа должна быть от 0,01 до 99 999 999,99 ₼ и содержать не более двух знаков после запятой.');
    }
}
