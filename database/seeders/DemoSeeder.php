<?php

namespace Database\Seeders;

use App\Domain\WorkflowService;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FundingSource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit opt-in examples; never called by the production reference seeder. */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data may only be installed in local or testing environments.');
        }
        $this->call([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
        if (User::where('email', 'requester@stocknest.test')->exists()) {
            $this->command?->warn('Demo users already exist; demo setup was skipped.');

            return;
        }
        $password = env('DEMO_PASSWORD') ?: Str::password(20);
        $department = Department::where('code', 'OPS')->firstOrFail();
        $fundIds = FundingSource::pluck('id')->all();
        $year = FiscalYear::where('year', now()->year)->firstOrFail();
        $users = [];
        foreach (['admin' => 'Super Administrator', 'budget' => 'Budget Officer', 'requester' => 'Department Requester', 'head' => 'Department Head', 'approver' => 'Fund Approver', 'procurement' => 'Procurement Officer', 'custodian' => 'Inventory Custodian', 'auditor' => 'Auditor'] as $slug => $role) {
            $user = new User;
            $user->forceFill(['name' => 'Demo '.$role, 'email' => $slug.'@stocknest.test', 'password' => $password, 'email_verified_at' => now(), 'is_active' => true, 'department_id' => $department->id])->save();
            $user->assignRole($role);
            $user->fundingSources()->sync($fundIds);
            $users[$slug] = $user;
        }
        $supplier = Supplier::firstOrCreate(['code' => 'DEMO-SUP'], ['name' => 'Demonstration Office Supply Co.', 'email' => 'orders@example.test']);
        $category = ItemCategory::where('code', 'OFFICE')->firstOrFail();
        $paper = Item::firstOrCreate(['sku' => 'DEMO-PAPER-A4'], ['name' => 'A4 multipurpose paper, 80 gsm', 'item_category_id' => $category->id, 'unit_of_measure_id' => UnitOfMeasure::where('code', 'REAM')->value('id'), 'standard_cost' => '250.00', 'reorder_point' => '15', 'minimum_stock' => '5', 'supplier_id' => $supplier->id]);
        $pen = Item::firstOrCreate(['sku' => 'DEMO-PEN-BLUE'], ['name' => 'Blue ballpoint pen', 'item_category_id' => $category->id, 'unit_of_measure_id' => UnitOfMeasure::where('code', 'PCS')->value('id'), 'standard_cost' => '12.00', 'reorder_point' => '20', 'minimum_stock' => '10', 'supplier_id' => $supplier->id]);
        $workflow = app(WorkflowService::class);
        $run = fn ($who, $operation, $data) => $workflow->execute($users[$who], $operation, $data, (string) Str::uuid());
        foreach (FundingSource::all() as $fund) {
            $scope = ['funding_source_id' => $fund->id, 'fiscal_year_id' => $year->id, 'department_id' => $department->id];
            $allocation = $run('budget', 'allocation.create', $scope + ['amount' => $fund->code === 'GAA' ? '500000.00' : '250000.00', 'reason' => 'Demonstration allocation authority']);
            $run('approver', 'allocation.decide', ['id' => $allocation['id'], 'decision' => 'approved', 'comment' => 'Demo authority confirmed.']);
            $projection = $run('budget', 'projection.save', $scope + ['justification' => 'Annual office consumables for demonstration.', 'lines' => [['item_id' => $paper->id, 'quantity' => '100', 'unit_price' => '250.00'], ['item_id' => $pen->id, 'quantity' => '200', 'unit_price' => '12.00']]]);
            $run('budget', 'projection.submit', ['id' => $projection['id']]);
            $run('approver', 'projection.decide', ['id' => $projection['id'], 'decision' => 'approved', 'comment' => 'Annual baseline approved.']);
            $req = $run('requester', 'request.save', $scope + ['required_on' => now()->addDays(7)->format('Y-m-d'), 'purpose' => 'Monthly office supply requirement', 'priority' => 'normal', 'lines' => [['item_id' => $paper->id, 'quantity' => '50', 'unit_price' => '250.00']]]);
            $run('requester', 'request.submit', ['id' => $req['id']]);
            $run('head', 'request.decide', ['id' => $req['id'], 'decision' => 'approved', 'comment' => 'Department need verified.']);
            $run('approver', 'request.decide', ['id' => $req['id'], 'decision' => 'approved', 'comment' => 'Fund verified.']);
            $request = ItemRequest::findOrFail($req['id']);
            $po = $run('procurement', 'purchase.create', ['item_request_id' => $request->id, 'supplier_id' => $supplier->id, 'lines' => [['line_id' => $request->lines->first()->id, 'quantity' => '50', 'unit_price' => '245.00']]]);
            $run('approver', 'purchase.approve', ['id' => $po['id'], 'comment' => 'Purchase authorized.']);
            $order = PurchaseOrder::findOrFail($po['id']);
            $run('custodian', 'receipt.create', ['id' => $order->id, 'warehouse_id' => Warehouse::firstOrFail()->id, 'delivery_reference' => 'DEMO-DR-'.$fund->code, 'lines' => [['line_id' => $order->lines->first()->id, 'quantity' => '50']]]);
            $run('budget', 'purchase.spend', ['id' => $order->id, 'amount' => '12250.00', 'document_reference' => 'DEMO-INV-'.$fund->code, 'final_payment' => true]);
            if ($fund->code === 'APPCSE') {
                $run('custodian', 'issuance.create', ['id' => $request->id, 'warehouse_id' => Warehouse::firstOrFail()->id, 'recipient' => 'Demo Operations representative', 'lines' => [['line_id' => $request->lines->first()->id, 'quantity' => '10']]]);
            }
        }
        $this->command?->info('Demo data installed. Login: admin@stocknest.test');
        $this->command?->info('Local demo password for all demo accounts: '.$password);
    }
}
