<?php

return [
    'title' => 'Дашборд',
    'description' => 'Общая статистика по системе',
    'access' => 'Доступ',
    'no_permission' => 'Для просмотра показателей у вас нет необходимых прав',
    'sections' => [
        'financial' => 'Финансы',
        'companies' => 'Компании',
        'attention' => 'Требует внимания',
    ],
    'billing' => [
        'title' => 'К выставлению',
        'count' => ':count счет за :period|:count счета за :period|:count счетов за :period',
        'empty' => 'За :period счетов к выставлению нет',
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
    'debt_breakdown' => [
        'title' => 'Структура долга',
        'show_all' => 'Показать все долги',
        'no_debt' => 'Задолженности нет',
        'open_company' => 'Открыть долг компании :name',
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
