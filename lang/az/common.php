<?php

return [
    'date' => [
        'required' => 'Tarixi daxil edin.',
        'invalid' => 'Tarixi gg/aa/iiii formatında düzgün daxil edin.',
        'min' => 'Tarix :date tarixindən əvvəl ola bilməz.',
        'max' => 'Tarix :date tarixindən sonra ola bilməz.',
        'placeholder' => 'gg/aa/iiii',
        'calendar' => 'Təqvimi aç',
    ],
    'table' => [
        'open_row' => 'Sətri aç',
    ],
    'statuses' => [
        'active' => 'Aktiv',
        'suspended' => 'Dayandırılıb',
        'archived' => 'Arxivləşdirilib',
        'draft' => 'Qaralama',
        'issued' => 'Rəsmiləşdirilib',
        'partially_paid' => 'Qismən ödənilib',
        'paid' => 'Ödənilib',
        'cancelled' => 'Ləğv edilib',
        'pending' => 'Təsdiq gözləyir',
        'confirmed' => 'Təsdiqlənib',
        'in_progress' => 'İcradadır',
        'completed' => 'Tamamlanıb',
        'one_time' => 'Birdəfəlik',
        'subscription' => 'Abunəlik',
    ],
    'payment_methods' => [
        'transfer' => 'Nağdsız',
        'card' => 'Kart',
        'cash' => 'Nağd',
    ],
    'line_types' => [
        'subscription' => 'Abunəlik',
        'order' => 'Birdəfəlik xidmət',
        'manual' => 'Əl ilə əlavə edilmiş mövqe',
    ],
];
