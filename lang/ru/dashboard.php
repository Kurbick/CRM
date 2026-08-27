<?php

return [
    'title' => 'Дашборд',
    'description' => 'Общая статистика по системе',
    'access' => 'Доступ',
    'no_permission' => 'Для просмотра показателей у вас нет необходимых прав',
    'sections' => [
        'financial' => 'Финансы',
        'companies' => 'Компании',
    ],
    'metrics' => [
        'total_debt' => 'Общий долг',
        'invoiced' => 'Выставлено',
        'overdue' => 'Просрочено',
        'paid' => 'Оплачено',
        'total_payments' => 'Всего платежей',
        'active_companies' => 'Активные компании',
        'subscriptions' => 'Подписки',
    ],
    'table' => [
        'company' => 'Компания',
        'status' => 'Статус',
        'debt' => 'Долг',
        'last_payment' => 'Последний платёж',
        'next_payment' => 'След. оплата',
        'open_company' => 'Открыть компанию :name',
        'overdue' => '⚠ Есть просрочка',
    ],
    'actions' => [
        'add_company' => '+ Добавить',
        'add_first_company' => 'Добавить первую',
    ],
    'empty' => [
        'companies' => 'Компаний пока нет.',
    ],
];
