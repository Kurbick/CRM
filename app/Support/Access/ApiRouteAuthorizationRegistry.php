<?php

namespace App\Support\Access;

use LogicException;

final class ApiRouteAuthorizationRegistry
{
    /** @var array<string, PermissionName> */
    private const ROUTE_PERMISSIONS = [
        'api.companies.index' => PermissionName::CompaniesView,
        'api.companies.store' => PermissionName::CompaniesCreate,
        'api.companies.show' => PermissionName::CompaniesView,
        'api.companies.update' => PermissionName::CompaniesUpdate,
        'api.companies.destroy' => PermissionName::CompaniesDelete,

        'api.companies.contacts.index' => PermissionName::CompaniesView,
        'api.companies.contacts.store' => PermissionName::CompanyContactsCreate,
        'api.contacts.show' => PermissionName::CompaniesView,
        'api.contacts.update' => PermissionName::CompanyContactsUpdate,
        'api.contacts.destroy' => PermissionName::CompanyContactsDelete,

        'api.companies.contracts.index' => PermissionName::ContractsView,
        'api.companies.contracts.store' => PermissionName::ContractsCreate,
        'api.contracts.show' => PermissionName::ContractsView,
        'api.contracts.update' => PermissionName::ContractsUpdate,
        'api.contracts.destroy' => PermissionName::ContractsDelete,

        'api.contracts.orders.index' => PermissionName::ContractsView,
        'api.contracts.orders.store' => PermissionName::ContractSubjectsCreate,
        'api.orders.show' => PermissionName::ContractsView,
        'api.orders.update' => PermissionName::ContractSubjectsUpdate,
        'api.orders.destroy' => PermissionName::ContractSubjectsDelete,

        'api.contracts.subscriptions.index' => PermissionName::ContractsView,
        'api.contracts.subscriptions.store' => PermissionName::ContractSubjectsCreate,
        'api.subscriptions.show' => PermissionName::ContractsView,
        'api.subscriptions.update' => PermissionName::ContractSubjectsUpdate,
        'api.subscriptions.destroy' => PermissionName::ContractSubjectsDelete,

        'api.companies.invoices.index' => PermissionName::InvoicesView,
        'api.companies.invoices.store' => PermissionName::InvoicesCreate,
        'api.invoices.show' => PermissionName::InvoicesView,
        'api.invoices.update' => PermissionName::InvoicesUpdate,
        'api.invoices.destroy' => PermissionName::InvoicesDelete,

        'api.invoices.payments.index' => PermissionName::PaymentsView,
        'api.invoices.payments.store' => PermissionName::PaymentsCreate,
        'api.payments.show' => PermissionName::PaymentsView,
        'api.payments.confirm' => PermissionName::PaymentsConfirm,

        'api.dashboard' => PermissionName::DashboardView,
        'api.dashboard.companies' => PermissionName::DashboardView,
        'api.dashboard.company' => PermissionName::DashboardView,
    ];

    /** @var list<string> */
    private const UNRESOLVED_ROUTE_NAMES = [
        'api.service-types.index',
        'api.service-types.store',
        'api.service-types.show',
        'api.service-types.update',
        'api.service-types.destroy',
        'api.service-types.items.index',
        'api.service-types.items.store',
        'api.items.show',
        'api.items.update',
        'api.items.destroy',
        'api.payments.update',
        'api.payments.destroy',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        return array_map(
            static fn (PermissionName $permission): string => $permission->value,
            self::ROUTE_PERMISSIONS,
        );
    }

    public static function has(string $routeName): bool
    {
        return array_key_exists($routeName, self::ROUTE_PERMISSIONS);
    }

    public static function permissionFor(string $routeName): string
    {
        $permission = self::ROUTE_PERMISSIONS[$routeName] ?? null;

        if (! $permission instanceof PermissionName) {
            throw new LogicException("No API permission mapping exists for route [{$routeName}].");
        }

        return $permission->value;
    }

    /** @return list<string> */
    public static function unresolvedRouteNames(): array
    {
        return self::UNRESOLVED_ROUTE_NAMES;
    }

    /** @return list<string> */
    public static function publicRouteNames(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function authenticatedOnlyRouteNames(): array
    {
        return [];
    }

    public static function classificationFor(string $routeName): string
    {
        if (self::has($routeName)) {
            return 'permission-protected domain route';
        }

        if (in_array($routeName, self::UNRESOLVED_ROUTE_NAMES, true)) {
            return 'unresolved product-decision route';
        }

        if (in_array($routeName, self::publicRouteNames(), true)) {
            return 'public authentication route';
        }

        if (in_array($routeName, self::authenticatedOnlyRouteNames(), true)) {
            return 'authenticated infrastructure route';
        }

        throw new LogicException("No API route classification exists for route [{$routeName}].");
    }

    /** @return list<string> */
    public static function classifiedRouteNames(): array
    {
        return array_values(array_unique([
            ...array_keys(self::ROUTE_PERMISSIONS),
            ...self::UNRESOLVED_ROUTE_NAMES,
            ...self::publicRouteNames(),
            ...self::authenticatedOnlyRouteNames(),
        ]));
    }
}
