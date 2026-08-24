<?php

namespace App\Support;

enum CompanyActivityVisibilityScope: string
{
    case Company = 'company';
    case Contacts = 'contacts';
    case Contracts = 'contracts';
    case Financials = 'financials';
    case Documents = 'documents';
}
