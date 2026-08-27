<?php

return [
    'title' => 'Əsas səhifə',
    'description' => 'Ümumi sistem statistikası',
    'access' => 'Giriş',
    'no_permission' => 'Göstəricilərə baxmaq üçün lazımi hüquqlarınız yoxdur',
    'sections' => [
        'financial' => 'Maliyyə',
        'companies' => 'Şirkətlər',
    ],
    'metrics' => [
        'total_debt' => 'Ümumi borc',
        'invoiced' => 'Rəsmiləşdirilib',
        'overdue' => 'Vaxtı keçmiş borc',
        'paid' => 'Ödənilib',
        'total_payments' => 'Ümumi ödənişlər',
        'active_companies' => 'Aktiv şirkətlər',
        'subscriptions' => 'Abunəliklər',
    ],
    'table' => [
        'company' => 'Şirkət',
        'status' => 'Status',
        'debt' => 'Borc',
        'last_payment' => 'Son ödəniş',
        'next_payment' => 'Növbəti ödəniş',
        'open_company' => 'Şirkəti aç: :name',
        'overdue' => '⚠ Vaxtı keçmiş borc var',
    ],
    'actions' => [
        'add_company' => '+ Əlavə et',
        'add_first_company' => 'İlk şirkəti əlavə et',
    ],
    'empty' => [
        'companies' => 'Hələ şirkət yoxdur.',
    ],
];
