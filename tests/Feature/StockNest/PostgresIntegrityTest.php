<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('PostgreSQL rejects direct SQL changes to audit history', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL immutable-ledger triggers.');
    }

    $id = DB::table('audit_logs')->insertGetId([
        'action' => 'integrity.fixture',
        'subject_type' => 'verification',
        'subject_id' => 1,
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $id)->delete()))
        ->toThrow(QueryException::class);
    expect(DB::table('audit_logs')->where('id', $id)->value('action'))->toBe('integrity.fixture');
});
