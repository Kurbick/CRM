<?php

return [
    'title' => 'Разовая услуга',
    'edit_title' => 'Редактирование разовой услуги',
    'back' => 'Назад',
    'back_to_contract' => 'Назад к договору',
    'basic_information' => 'Основная информация',
    'name' => 'Название',
    'name_placeholder' => 'Например: разработка сайта',
    'date' => 'Дата',
    'amount' => 'Сумма (₼)',
    'payment_terms' => 'Срок оплаты (дней)',
    'payment_terms_placeholder' => 'Количество дней',
    'status' => 'Статус',
    'comment' => 'Комментарий',
    'save' => 'Сохранить',
    'cancel' => 'Отмена',
    'statuses' => [
        'in_progress' => 'В работе',
        'completed' => 'Завершён',
        'cancelled' => 'Отменён',
    ],
    'flash' => [
        'created' => 'Разовая услуга успешно добавлена.',
        'updated' => 'Разовая услуга обновлена.',
        'deleted' => 'Разовая услуга удалена.',
    ],
    'errors' => [
        'delete_invoice' => 'Невозможно удалить разовую услугу, поскольку она уже используется в инвойсе.',
    ],
    'validation' => [
        'payment_terms_required' => 'Укажите срок оплаты в днях.',
        'payment_terms_integer' => 'Срок оплаты должен быть целым числом дней.',
    ],
];
