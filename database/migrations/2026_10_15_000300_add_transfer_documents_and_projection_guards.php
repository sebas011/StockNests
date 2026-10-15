<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['funding_source_id', 'fiscal_year_id']);
        });
        Schema::table('annual_projections', function (Blueprint $table) {
            $table->unique(['original_projection_id', 'revision']);
        });
        DB::statement("CREATE UNIQUE INDEX approved_projection_baseline_unique ON annual_projections (funding_source_id, fiscal_year_id, department_id) WHERE status = 'approved' AND original_projection_id IS NULL");
        DB::statement('CREATE UNIQUE INDEX users_normalized_email_unique ON users (lower(email))');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfer_valid CHECK (quantity > 0 AND source_warehouse_id <> destination_warehouse_id)');
            DB::unprepared('CREATE TRIGGER stock_transfers_immutable BEFORE UPDATE OR DELETE ON stock_transfers FOR EACH ROW EXECUTE FUNCTION stocknest_immutable()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
        DB::statement('DROP INDEX IF EXISTS approved_projection_baseline_unique');
        DB::statement('DROP INDEX IF EXISTS users_normalized_email_unique');
        Schema::table('annual_projections', fn (Blueprint $table) => $table->dropUnique(['original_projection_id', 'revision']));
    }
};
