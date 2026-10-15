<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\ItemCategory;
use App\Models\SystemSetting;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

final class StockNestSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['GAA', 'APPCSE'] as $code) {
            FundingSource::firstOrCreate(['code' => $code], ['name' => $code, 'budget_control' => 'hard', 'is_active' => true]);
        }
        foreach (['ADMIN' => 'Administration', 'FIN' => 'Finance', 'OPS' => 'Operations'] as $code => $name) {
            Department::firstOrCreate(['code' => $code], ['name' => $name]);
        }
        FiscalYear::firstOrCreate(['year' => now()->year], ['starts_on' => now()->startOfYear()->format('Y-m-d'), 'ends_on' => now()->endOfYear()->format('Y-m-d'), 'status' => 'open']);
        foreach (['OFFICE' => 'Office supplies', 'ICT' => 'Information technology', 'GENERAL' => 'General supplies'] as $code => $name) {
            ItemCategory::firstOrCreate(['code' => $code], ['name' => $name]);
        }
        foreach (['PCS' => 'Piece', 'REAM' => 'Ream', 'BOX' => 'Box', 'UNIT' => 'Unit'] as $code => $name) {
            UnitOfMeasure::firstOrCreate(['code' => $code], ['name' => $name]);
        }
        Warehouse::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main storeroom']);
        foreach (['organization_name' => 'StockNest', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'request_approval_roles' => ['Department Head', 'Fund Approver'], 'email_notifications' => false] as $key => $value) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
