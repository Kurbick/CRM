<?php

return [
    'title' => 'Əsas səhifə',
    'description' => 'Ümumi sistem statistikası',
    'access' => 'Giriş',
    'no_permission' => 'Göstəricilərə baxmaq üçün lazımi hüquqlarınız yoxdur',
    'sections' => [
        'financial' => 'Maliyyə',
        'companies' => 'Şirkətlər',
        'attention' => 'Diqqət tələb edir',
    ],
    'period' => [
        'trigger' => 'Dövr',
        'options' => [
            'this_month' => 'Bu ay',
            '3m' => '3 ay',
            '6m' => '6 ay',
            '1y' => 'İl',
            'all' => 'Bütün vaxt',
            'custom' => 'Fərdi dövr',
        ],
        'from' => 'Başlanğıc',
        'to' => 'Son',
        'apply' => 'Tətbiq et',
    ],
    'billing' => [
        'title' => 'Rəsmiləşdirilməlidir',
        'count' => ':count hesab :period üçün',
        'empty' => ':period üçün rəsmiləşdiriləcək hesab yoxdur',
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
    'debt_breakdown' => [
        'title' => 'Borc strukturu',
        'show_all' => 'Bütün borclara bax',
        'no_debt' => 'Borc yoxdur',
        'open_company' => ':name şirkətinin borcunu aç',
    ],
    'overdue_breakdown' => [
        'title' => 'Vaxtı keçmiş borcun strukturu',
        'show_all' => 'Bütün vaxtı keçmiş borclara bax',
        'open_company' => ':name şirkətinin gecikmiş borcunu aç',
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
