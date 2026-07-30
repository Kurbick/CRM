<?php

namespace App\Enums;

enum SubscriptionBillingPeriod: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';
    case Custom = 'custom';
}
