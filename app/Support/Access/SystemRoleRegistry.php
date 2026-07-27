<?php

namespace App\Support\Access;

final class SystemRoleRegistry
{
    /** @return list<array{name: SystemRole, display_name: string, description: string, sort_order: int, permissions: list<PermissionName>}> */
    public static function all(): array
    {
        return [
            ['name' => SystemRole::Administrator, 'display_name' => 'Администратор', 'description' => 'Полный системный доступ.', 'sort_order' => 10, 'permissions' => PermissionName::cases()],
            ['name' => SystemRole::Accountant, 'display_name' => 'Бухгалтер', 'description' => 'Работа с инвойсами, платежами и финансовой информацией.', 'sort_order' => 20, 'permissions' => self::permissions(SystemRole::Accountant)],
            ['name' => SystemRole::Viewer, 'display_name' => 'Только просмотр', 'description' => 'Просмотр основных данных CRM без возможности изменения.', 'sort_order' => 30, 'permissions' => self::permissions(SystemRole::Viewer)],
        ];
    }

    /** @return list<PermissionName> */
    public static function permissions(SystemRole $role): array
    {
        return match ($role) {
            SystemRole::Administrator => PermissionName::cases(),
            SystemRole::Accountant => self::fromSlugs([
                'dashboard.view', 'companies.view', 'companies.financials.view', 'contracts.view',
                'contract_documents.download', 'invoices.view', 'invoices.create', 'invoices.update',
                'invoices.issue', 'invoices.print', 'payments.view', 'payments.create',
                'payments.confirm', 'payments.cancel',
            ]),
            SystemRole::Viewer => self::fromSlugs([
                'dashboard.view', 'companies.view', 'contracts.view', 'contract_documents.download',
                'invoices.view', 'invoices.print', 'payments.view',
            ]),
        };
    }

    /** @param list<string> $slugs @return list<PermissionName> */
    private static function fromSlugs(array $slugs): array
    {
        return array_map(PermissionName::from(...), $slugs);
    }
}
