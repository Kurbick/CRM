<?php

namespace App\Support\Access;

enum PermissionName: string
{
    case DashboardView = 'dashboard.view';
    case CompaniesView = 'companies.view';
    case CompaniesFinancialsView = 'companies.financials.view';
    case CompaniesCreate = 'companies.create';
    case CompaniesUpdate = 'companies.update';
    case CompaniesDelete = 'companies.delete';
    case CompanyContactsCreate = 'company_contacts.create';
    case CompanyContactsUpdate = 'company_contacts.update';
    case CompanyContactsDelete = 'company_contacts.delete';
    case ContractsView = 'contracts.view';
    case ContractsCreate = 'contracts.create';
    case ContractsUpdate = 'contracts.update';
    case ContractsDelete = 'contracts.delete';
    case ContractDocumentsDownload = 'contract_documents.download';
    case ContractDocumentsUpload = 'contract_documents.upload';
    case ContractDocumentsDelete = 'contract_documents.delete';
    case ContractSubjectsCreate = 'contract_subjects.create';
    case ContractSubjectsUpdate = 'contract_subjects.update';
    case ContractSubjectsDelete = 'contract_subjects.delete';
    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesUpdate = 'invoices.update';
    case InvoicesIssue = 'invoices.issue';
    case InvoicesCancel = 'invoices.cancel';
    case InvoicesDelete = 'invoices.delete';
    case InvoicesPrint = 'invoices.print';
    case PaymentsView = 'payments.view';
    case PaymentsCreate = 'payments.create';
    case PaymentsConfirm = 'payments.confirm';
    case PaymentsCancel = 'payments.cancel';
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersActivate = 'users.activate';
    case UsersDeactivate = 'users.deactivate';
    case UsersResetPassword = 'users.reset_password';
    case UsersAssignRole = 'users.assign_role';
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';
    case AccessPermissionsView = 'access_permissions.view';
    case AccessPermissionsUpdate = 'access_permissions.update';
}
