<?php

return [
    'currency' => env('STOCKNEST_CURRENCY', 'PHP'),
    'organization' => env('STOCKNEST_ORGANIZATION', 'Office of the Vice President for Academic Affairs'),
    'office_workflow' => (bool) env('STOCKNEST_OFFICE_WORKFLOW', true),
    'public_registration' => false,
    'portable' => (bool) env('STOCKNEST_PORTABLE', false),
    'demo' => (bool) env('STOCKNEST_DEMO', false),
    'demo_password' => env('DEMO_PASSWORD'),
    'notification_email' => (bool) env('STOCKNEST_NOTIFICATION_EMAIL', false),
    'reports' => [
        'pdf_max_rows' => 1000,
        'pdf_max_bytes' => 5000000,
        'xlsx_max_rows' => 10000,
        'xlsx_max_bytes' => 20000000,
    ],
];
