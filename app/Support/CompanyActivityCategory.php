<?php

namespace App\Support;

enum CompanyActivityCategory: string
{
    case Company = 'company';
    case Contacts = 'contacts';
    case Contracts = 'contracts';
    case Invoices = 'invoices';
    case Payments = 'payments';
    case Documents = 'documents';
}
