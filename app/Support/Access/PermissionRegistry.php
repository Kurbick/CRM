<?php

namespace App\Support\Access;

final class PermissionRegistry
{
    /** @return list<array{name: PermissionName, label: string, module: string, module_label: string, module_order: int, action_order: int}> */
    public static function all(): array
    {
        $modules = [
            ['dashboard', 'Панель', 10, [['view', 'Просмотр панели']]],
            ['companies', 'Компании', 20, [['view', 'Просмотр компаний'], ['financials.view', 'Просмотр финансовой информации'], ['create', 'Создание компаний'], ['update', 'Редактирование компаний'], ['delete', 'Удаление компаний']]],
            ['company_contacts', 'Контактные лица', 30, [['create', 'Создание контактных лиц'], ['update', 'Редактирование контактных лиц'], ['delete', 'Удаление контактных лиц']]],
            ['contracts', 'Договоры', 40, [['view', 'Просмотр договоров'], ['create', 'Создание договоров'], ['update', 'Редактирование договоров'], ['delete', 'Удаление договоров']]],
            ['contract_documents', 'Документы договора', 50, [['download', 'Скачивание документов'], ['upload', 'Загрузка документов'], ['delete', 'Удаление документов']]],
            ['contract_subjects', 'Предметы договора', 60, [['create', 'Создание предметов договора'], ['update', 'Редактирование предметов договора'], ['delete', 'Удаление предметов договора']]],
            ['invoices', 'Инвойсы', 70, [['view', 'Просмотр инвойсов'], ['create', 'Создание инвойсов'], ['update', 'Редактирование инвойсов'], ['issue', 'Выставление инвойсов'], ['cancel', 'Отмена инвойсов'], ['delete', 'Удаление инвойсов'], ['print', 'Печать инвойсов']]],
            ['payments', 'Платежи', 80, [['view', 'Просмотр платежей'], ['create', 'Создание платежей'], ['confirm', 'Подтверждение платежей'], ['cancel', 'Отмена платежей']]],
            ['users', 'Пользователи', 90, [['view', 'Просмотр пользователей'], ['create', 'Создание пользователей'], ['update', 'Редактирование пользователей'], ['activate', 'Активация пользователей'], ['deactivate', 'Отключение пользователей'], ['reset_password', 'Сброс пароля пользователя'], ['assign_role', 'Назначение группы пользователю']]],
            ['roles', 'Группы', 100, [['view', 'Просмотр групп'], ['create', 'Создание групп'], ['update', 'Редактирование групп'], ['delete', 'Удаление групп']]],
            ['access_permissions', 'Права доступа', 110, [['view', 'Просмотр прав доступа'], ['update', 'Изменение прав доступа']]],
        ];

        $permissions = [];
        foreach ($modules as [$module, $moduleLabel, $moduleOrder, $actions]) {
            foreach ($actions as $index => [$action, $label]) {
                $permissions[] = [
                    'name' => PermissionName::from("{$module}.{$action}"),
                    'label' => $label,
                    'module' => $module,
                    'module_label' => $moduleLabel,
                    'module_order' => $moduleOrder,
                    'action_order' => ($index + 1) * 10,
                ];
            }
        }

        return $permissions;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (array $item) => $item['name']->value, self::all());
    }

    public static function contains(string $ability): bool
    {
        return PermissionName::tryFrom($ability) !== null;
    }

    /** @return list<array{module: string, label: string, order: int, permissions: list<array{name: PermissionName, label: string, action_order: int}>}> */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $item) {
            $module = $item['module'];
            $groups[$module] ??= [
                'module' => $module,
                'label' => $item['module_label'],
                'order' => $item['module_order'],
                'permissions' => [],
            ];
            $groups[$module]['permissions'][] = [
                'name' => $item['name'],
                'label' => $item['label'],
                'action_order' => $item['action_order'],
            ];
        }

        return array_values($groups);
    }
}
