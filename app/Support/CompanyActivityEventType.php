<?php

namespace App\Support;

enum CompanyActivityEventType: string
{
    case CompanyCreated = 'company.created';
    case CompanyUpdated = 'company.updated';
    case ContactCreated = 'contact.created';
    case ContactUpdated = 'contact.updated';
    case ContactDeleted = 'contact.deleted';
    case ContractCreated = 'contract.created';
    case ContractStatusChanged = 'contract.status_changed';
    case ContractSubjectCreated = 'contract_subject.created';
    case ContractSubjectUpdated = 'contract_subject.updated';
    case ContractSubjectDeleted = 'contract_subject.deleted';
    case DocumentUploaded = 'document.uploaded';
    case DocumentDeleted = 'document.deleted';
    case InvoiceCreated = 'invoice.created';
    case InvoiceIssued = 'invoice.issued';
    case InvoiceCancelled = 'invoice.cancelled';
    case PaymentPendingCreated = 'payment.pending_created';
    case PaymentConfirmed = 'payment.confirmed';
    case PaymentCancelled = 'payment.cancelled';
    case CreditApplied = 'credit.applied';
}
