<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_requests', fn (Blueprint $table) => $table->string('channel')->default('supply')->index());
        Schema::table('item_request_lines', fn (Blueprint $table) => $table->decimal('received_quantity', 18, 4)->default(0));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->string('authorization_reference')->nullable());
        Schema::create('supply_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('item_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('delivery_reference');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('supply_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_request_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->unique(['supply_receipt_id', 'item_request_line_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE item_requests ADD CONSTRAINT item_requests_channel_check CHECK (channel IN ('supply', 'cash'))");
            DB::statement('ALTER TABLE item_request_lines ADD CONSTRAINT item_request_received_quantity_check CHECK (received_quantity >= 0 AND received_quantity <= quantity)');
            DB::statement('ALTER TABLE supply_receipt_lines ADD CONSTRAINT supply_receipt_positive_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_receipt_lines');
        Schema::dropIfExists('supply_receipts');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE item_requests DROP CONSTRAINT IF EXISTS item_requests_channel_check');
            DB::statement('ALTER TABLE item_request_lines DROP CONSTRAINT IF EXISTS item_request_received_quantity_check');
        }
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('authorization_reference'));
        Schema::table('item_request_lines', fn (Blueprint $table) => $table->dropColumn('received_quantity'));
        Schema::table('item_requests', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn('channel');
        });
    }
};
