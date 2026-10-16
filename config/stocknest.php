<?php

return [
    'currency' => env('STOCKNEST_CURRENCY', 'PHP'),
    'organization' => env('STOCKNEST_ORGANIZATION', 'StockNest'),
    'public_registration' => false,
    'portable' => (bool) env('STOCKNEST_PORTABLE', false),
    'demo' => (bool) env('STOCKNEST_DEMO', false),
    'notification_email' => (bool) env('STOCKNEST_NOTIFICATION_EMAIL', false),
    'allow_self_approval' => false,
];
