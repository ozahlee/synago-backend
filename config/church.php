<?php

/*
| Defaults for church-wide settings. Admins can override these at runtime via PUT /api/v1/settings
| (stored in the `settings` table); these values apply until then.
*/
return [
    // Calendar and reminder times are wall-clock times in this zone.
    'timezone' => env('CHURCH_TIMEZONE', 'Africa/Kampala'),
    'currency' => env('CHURCH_CURRENCY', 'UGX'),

    'defaults' => [
        'churchName' => env('CHURCH_NAME', 'SJW'),
        // anglican | pentecostal | other — drives terminology (confirmation vs child dedication, etc.)
        'denomination' => env('CHURCH_DENOMINATION', 'anglican'),
        'diocese' => env('CHURCH_DIOCESE'),
        'address' => env('CHURCH_ADDRESS'),
        'pledgePrefix' => env('CHURCH_PLEDGE_PREFIX', 'SJW'),
        'services' => ['First Service', 'Second Service', 'Third Service', 'Youth Service'],
        // Start times shown on the calendar, same order as services
        'serviceTimes' => ['07:30', '09:30', '11:30', '14:00'],
        // Reminder safeguards
        'quietHoursStart' => '20:00',
        'quietHoursEnd' => '07:00',
        'smsMonthlyBudget' => 3000,
        // Month the financial year starts (1 = January); drives the accounting statements
        'financialYearStartMonth' => (int) env('CHURCH_FINANCIAL_YEAR_START', 1),
        // What a candidate must bring / complete before baptism, confirmation or dedication.
        // `kinds` limits a requirement to some rites; `fee` (optional) is the amount expected.
        'sacramentRequirements' => [
            ['key' => 'class', 'label' => 'Completed preparation class', 'kinds' => ['baptism', 'confirmation', 'dedication'], 'fee' => null],
            ['key' => 'bible', 'label' => 'Own Bible', 'kinds' => ['baptism', 'confirmation'], 'fee' => null],
            ['key' => 'hymn_book', 'label' => 'Hymn book', 'kinds' => ['baptism', 'confirmation'], 'fee' => null],
            ['key' => 'prayer_book', 'label' => 'Prayer book', 'kinds' => ['confirmation'], 'fee' => null],
            ['key' => 'birth_certificate', 'label' => 'Birth certificate / notification', 'kinds' => ['baptism', 'dedication'], 'fee' => null],
            ['key' => 'baptism_certificate', 'label' => 'Baptism certificate', 'kinds' => ['confirmation'], 'fee' => null],
            ['key' => 'godparents', 'label' => 'Godparents confirmed (baptised & confirmed)', 'kinds' => ['baptism'], 'fee' => null],
            ['key' => 'fee', 'label' => 'Fee paid', 'kinds' => ['baptism', 'confirmation', 'dedication'], 'fee' => 10000],
        ],
    ],
];
