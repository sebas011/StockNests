<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('funding_sources', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('accounting_reference')->nullable();
            $t->string('budget_control')->default('hard');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('funding_source_user', function (Blueprint $t) {
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['funding_source_id', 'user_id']);
        });
        Schema::create('fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('year')->unique();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status')->default('open');
            $t->timestamps();
        });
        foreach (['item_categories', 'units_of_measure', 'warehouses', 'suppliers'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->string('code')->unique();
                $t->string('name');
                if ($table === 'suppliers') {
                    $t->string('email')->nullable();
                    $t->text('address')->nullable();
                }
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->foreignId('item_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $t->text('description')->nullable();
            $t->text('specifications')->nullable();
            $t->string('item_type')->default('consumable');
            $t->decimal('minimum_stock', 18, 4)->default(0);
            $t->decimal('reorder_point', 18, 4)->default(0);
            $t->decimal('standard_cost', 18, 2)->default(0);
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('budget_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $t->decimal('authorized_amount', 18, 2)->default(0);
            $t->unique(['funding_source_id', 'fiscal_year_id']);
            $t->timestamps();
        });
        Schema::create('budget_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_allocation_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('status')->default('submitted');
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('annual_projections', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('original_projection_id')->nullable()->constrained('annual_projections')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->string('status')->default('draft');
            $t->decimal('total', 18, 2);
            $t->text('justification');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['funding_source_id', 'fiscal_year_id', 'status']);
        });
        Schema::create('projection_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('annual_projection_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('total', 18, 2);
            $t->string('priority')->default('normal');
            $t->unsignedSmallInteger('planned_month')->nullable();
            $t->text('remarks')->nullable();
            $t->unique(['annual_projection_id', 'item_id']);
        });
        Schema::create('item_requests', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->date('required_on');
            $t->text('purpose');
            $t->string('priority')->default('normal');
            $t->string('status')->default('draft');
            $t->unsignedSmallInteger('approval_stage')->default(0);
            $t->boolean('is_unplanned')->default(false);
            $t->decimal('estimated_total', 18, 2);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['funding_source_id', 'department_id', 'status']);
        });
        Schema::create('item_request_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->decimal('approved_quantity', 18, 4)->default(0);
            $t->decimal('fulfilled_quantity', 18, 4)->default(0);
            $t->decimal('unit_price', 18, 2);
            $t->boolean('is_unplanned')->default(false);
            $t->unique(['item_request_id', 'item_id']);
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('item_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status')->default('draft');
            $t->decimal('total', 18, 2);
            $t->decimal('committed_amount', 18, 2)->default(0);
            $t->decimal('spent_amount', 18, 2)->default(0);
            $t->text('remarks')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_request_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->decimal('received_quantity', 18, 4)->default(0);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('total', 18, 2);
            $t->unique(['purchase_order_id', 'item_id']);
        });
        Schema::create('financial_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_allocation_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind');
            $t->decimal('commitment_delta', 18, 2)->default(0);
            $t->decimal('expenditure_delta', 18, 2)->default(0);
            $t->text('reference');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->string('delivery_reference');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_cost', 18, 2);
        });
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 4)->default(0);
            $t->unique(['funding_source_id', 'warehouse_id', 'item_id']);
            $t->timestamps();
        });
        Schema::create('stock_issuances', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('item_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->string('recipient');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('stock_issuance_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_issuance_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_request_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->decimal('returned_quantity', 18, 4)->default(0);
        });
        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_request_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_balance_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->string('status')->default('active');
            $t->timestamps();
            $t->unique(['item_request_line_id', 'stock_balance_id']);
        });
        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('counter_id')->constrained('users')->restrictOnDelete();
            $t->date('count_month');
            $t->timestamp('cutoff_at');
            $t->string('status')->default('counting');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('reconciled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_count_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->decimal('system_quantity', 18, 4);
            $t->decimal('physical_quantity', 18, 4)->nullable();
            $t->decimal('variance', 18, 4)->nullable();
            $t->text('variance_reason')->nullable();
            $t->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('counted_at')->nullable();
            $t->unique(['inventory_count_id', 'item_id']);
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_balance_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind');
            $t->decimal('quantity_delta', 18, 4);
            $t->decimal('balance_after', 18, 4);
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->text('remarks');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['source_type', 'source_id']);
        });
        Schema::create('approval_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedSmallInteger('stage')->default(1);
            $t->string('decision');
            $t->text('comment')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('funding_source_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->json('before_values')->nullable();
            $t->json('after_values')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['funding_source_id', 'created_at']);
        });
        Schema::create('idempotent_commands', function (Blueprint $t) {
            $t->id();
            $t->uuid('key')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('operation');
            $t->string('request_hash', 64);
            $t->json('result')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value');
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['stock_balances' => 'quantity >= 0', 'budget_allocations' => 'authorized_amount >= 0', 'items' => 'standard_cost >= 0 AND minimum_stock >= 0 AND reorder_point >= 0', 'projection_lines' => 'quantity > 0 AND unit_price >= 0', 'item_request_lines' => 'quantity > 0 AND approved_quantity >= 0 AND fulfilled_quantity >= 0 AND fulfilled_quantity <= approved_quantity', 'purchase_order_lines' => 'quantity > 0 AND received_quantity >= 0 AND received_quantity <= quantity', 'goods_receipt_lines' => 'quantity > 0', 'stock_issuance_lines' => 'quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity'] as $table => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_valid_amounts CHECK ({$check})");
            }
            DB::unprepared("CREATE OR REPLACE FUNCTION stocknest_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Immutable ledger records cannot be changed'; END; $$");
            foreach (['audit_logs', 'stock_movements', 'financial_entries', 'approval_decisions'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION stocknest_immutable()");
            }
        }
    }

    public function down(): void
    {
        foreach (['system_settings', 'idempotent_commands', 'audit_logs', 'approval_decisions', 'stock_movements', 'inventory_count_lines', 'inventory_counts', 'stock_reservations', 'stock_issuance_lines', 'stock_issuances', 'stock_balances', 'goods_receipt_lines', 'goods_receipts', 'financial_entries', 'purchase_order_lines', 'purchase_orders', 'item_request_lines', 'item_requests', 'projection_lines', 'annual_projections', 'budget_adjustments', 'budget_allocations', 'items', 'suppliers', 'warehouses', 'units_of_measure', 'item_categories', 'fiscal_years', 'funding_source_user', 'funding_sources', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS stocknest_immutable()');
        }
    }
};
