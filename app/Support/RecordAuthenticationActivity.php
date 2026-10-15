<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class RecordAuthenticationActivity
{
    public static function record(string $event, ?int $userId): void
    {
        DB::table('login_activities')->insert([
            'user_id' => $userId, 'event' => $event,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
