<?php

return [
    'date' => [
        'required' => 'Введите дату.',
        'invalid' => 'Введите корректную дату в формате дд/мм/гггг.',
        'min' => 'Дата должна быть не раньше :date.',
        'max' => 'Дата должна быть не позже :date.',
        'placeholder' => 'дд/мм/гггг',
        'calendar' => 'Открыть календарь',
    ],
    'table' => [
        'open_row' => 'Открыть строку',
    ],
    'statuses' => [
        'active' => 'Активен',
        'suspended' => 'Приостановлен',
        'archived' => 'Архив',
        'draft' => 'Черновик',
        'issued' => 'Выставлен',
        'partially_paid' => 'Частично оплачен',
        'paid' => 'Оплачен',
        'cancelled' => 'Отменён',
        'pending' => 'Ожидает подтверждения',
        'confirmed' => 'Подтверждён',
        'in_progress' => 'В работе',
        'completed' => 'Завершён',
        'one_time' => 'Разовая',
        'subscription' => 'Подписка',
    ],
    'payment_methods' => [
        'transfer' => 'Безналичный',
        'card' => 'Карта',
        'cash' => 'Наличные',
    ],
    'line_types' => [
        'subscription' => 'Подписка',
        'order' => 'Разовая услуга',
        'manual' => 'Ручная позиция',
    ],
];
