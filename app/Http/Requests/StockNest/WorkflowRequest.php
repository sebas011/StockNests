<?php

namespace App\Http\Requests\StockNest;

use App\Domain\OfficeWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WorkflowRequest extends FormRequest
{
    public const OPERATIONS = ['master.save', 'fiscal-year.reopen', 'allocation.create', 'allocation.decide', 'projection.save', 'projection.submit', 'projection.decide', 'request.save', 'request.submit', 'request.decide', 'request.cancel', 'purchase.create', 'purchase.approve', 'purchase.cancel', 'purchase.spend', 'receipt.create', 'supply.receive', 'stock.consume', 'issuance.create', 'issuance.return', 'reservation.create', 'transfer.create', 'count.create', 'count.encode', 'count.submit', 'count.decide', 'count.reconcile'];

    protected function prepareForValidation(): void
    {
        if (OfficeWorkflow::enabled() && in_array($this->route('operation'), ['projection.save', 'request.save'], true)) {
            $this->merge(['department_id' => OfficeWorkflow::departmentId()]);
            if ($this->route('operation') === 'request.save') {
                $this->merge(['priority' => 'normal']);
            }
        }
    }

    public function authorize(): bool
    {
        $operation = (string) $this->route('operation');
        if (OfficeWorkflow::enabled() && in_array($operation, ['allocation.decide', 'projection.submit', 'projection.decide', 'request.decide', 'purchase.approve', 'reservation.create', 'transfer.create', 'issuance.create', 'issuance.return', 'count.decide'], true)) {
            return false;
        }
        $permission = match ($operation) {
            'master.save' => 'master-data.manage',
            'fiscal-year.reopen' => 'fiscal.reopen',
            'allocation.create', 'projection.save', 'projection.submit', 'purchase.spend' => 'budgets.manage',
            'allocation.decide', 'projection.decide' => 'budget.approve',
            'purchase.approve' => 'fund.approve',
            'request.decide' => 'requests.approve',
            'request.save', 'request.submit', 'request.cancel' => 'requests.create',
            'purchase.create', 'purchase.cancel' => 'procurement.manage',
            'count.decide' => 'counts.approve',
            default => 'inventory.manage',
        };

        return in_array($operation, self::OPERATIONS, true) && $this->user()?->is_active && $this->user()->hasPermissionTo($permission);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $operation = (string) $this->route('operation');
        $rules = ['idempotency_key' => ['required', 'uuid']];
        $id = ['required', 'integer', 'min:1'];
        $quantity = ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/', 'numeric', 'gt:0'];
        $money = ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/'];
        if ($operation === 'fiscal-year.reopen') {
            return $rules + ['id' => $id, 'reason' => ['required', 'string', 'max:3000']];
        }
        if ($operation === 'master.save') {
            $rules += ['kind' => ['required', Rule::in(['fund', 'department', 'fiscal-year', 'category', 'unit', 'warehouse', 'supplier', 'item'])], 'id' => ['nullable', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']];
            $kind = $this->input('kind');
            if (OfficeWorkflow::enabled()) {
                $rules['kind'] = ['required', Rule::in(['fund', 'fiscal-year', 'category', 'unit', 'warehouse', 'supplier', 'item'])];
            }
            if ($kind === 'fiscal-year') {
                return $rules + ['year' => ['required', 'integer', 'between:2000,2200', Rule::unique('fiscal_years')->ignore($this->integer('id'))], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'status' => ['required', Rule::in(['open', 'closed'])]];
            }
            $rules += ['name' => ['required', 'string', 'max:200']];
            if ($kind === 'item') {
                return $rules + ['sku' => ['required', 'string', 'max:80', Rule::unique('items')->ignore($this->integer('id'))], 'item_category_id' => ['required', 'exists:item_categories,id'], 'unit_of_measure_id' => ['required', 'exists:units_of_measure,id'], 'supplier_id' => ['nullable', 'exists:suppliers,id'], 'description' => ['nullable', 'string', 'max:5000'], 'specifications' => ['nullable', 'string', 'max:5000'], 'item_type' => ['required', Rule::in(['consumable', 'equipment', 'supply'])], 'minimum_stock' => ['required', 'numeric', 'min:0', 'decimal:0,4'], 'reorder_point' => ['required', 'numeric', 'min:0', 'decimal:0,4'], 'standard_cost' => $money];
            }
            $table = ['fund' => 'funding_sources', 'department' => 'departments', 'category' => 'item_categories', 'unit' => 'units_of_measure', 'warehouse' => 'warehouses', 'supplier' => 'suppliers'][$kind] ?? 'departments';
            $rules += ['code' => ['required', 'string', 'max:50', Rule::unique($table)->ignore($this->integer('id'))]];
            if ($kind === 'fund') {
                $rules += ['accounting_reference' => ['nullable', 'string', 'max:200'], 'budget_control' => ['required', Rule::in(['hard', 'soft'])]];
            }
            if ($kind === 'supplier') {
                $rules += ['email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:3000']];
            }

            return $rules;
        }
        if (in_array($operation, ['allocation.create', 'projection.save', 'request.save', 'count.create', 'transfer.create'], true)) {
            $rules += ['funding_source_id' => ['required', 'exists:funding_sources,id']];
        }
        if (in_array($operation, ['allocation.create', 'projection.save', 'request.save', 'count.create'], true)) {
            $rules += ['fiscal_year_id' => ['required', 'exists:fiscal_years,id']];
        }
        if (in_array($operation, ['projection.save', 'request.save'], true)) {
            $rules += ['id' => ['nullable', 'integer', 'min:1'], 'department_id' => ['required', 'exists:departments,id'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.item_id' => ['required', 'distinct', 'exists:items,id'], 'lines.*.quantity' => $quantity, 'lines.*.unit_price' => $money];
            if ($operation === 'projection.save') {
                return $rules + ['original_projection_id' => ['nullable', 'exists:annual_projections,id'], 'justification' => ['required', 'string', 'max:5000'], 'lines.*.priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])], 'lines.*.planned_month' => ['nullable', 'integer', 'between:1,12'], 'lines.*.remarks' => ['nullable', 'string', 'max:2000']];
            }

            return $rules + ['channel' => [OfficeWorkflow::enabled() ? 'required' : 'sometimes', Rule::in(['supply', 'cash'])], 'required_on' => ['required', 'date'], 'purpose' => ['required', 'string', 'max:5000'], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])]];
        }
        if ($operation === 'allocation.create') {
            return $rules + ['amount' => ['required', 'regex:/^-?\d{1,14}(\.\d{1,2})?$/', 'numeric', 'not_in:0'], 'reason' => ['required', 'string', 'max:5000']];
        }
        if ($operation === 'purchase.create') {
            return $rules + ['authorization_reference' => [OfficeWorkflow::enabled() ? 'required' : 'nullable', 'string', 'max:250'], 'item_request_id' => ['required', 'exists:item_requests,id'], 'supplier_id' => ['required', 'exists:suppliers,id'], 'remarks' => ['nullable', 'string', 'max:3000'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.line_id' => ['required', 'integer', 'distinct'], 'lines.*.quantity' => $quantity, 'lines.*.unit_price' => $money];
        }
        if ($operation === 'count.create') {
            return $rules + ['warehouse_id' => ['required', 'exists:warehouses,id'], 'counter_id' => ['required', 'exists:users,id'], 'count_month' => ['required', 'date_format:Y-m-d']];
        }
        if ($operation === 'transfer.create') {
            return $rules + ['fiscal_year_id' => ['nullable', 'exists:fiscal_years,id'], 'effective_on' => ['nullable', 'date_format:Y-m-d'], 'warehouse_id' => ['required', 'exists:warehouses,id'], 'destination_warehouse_id' => ['required', 'exists:warehouses,id', 'different:warehouse_id'], 'item_id' => ['required', 'exists:items,id'], 'quantity' => $quantity, 'reason' => ['required', 'string', 'max:3000']];
        }
        $rules += ['id' => $id];
        if ($operation === 'stock.consume') {
            return $rules + ['quantity' => $quantity, 'recipient' => ['required', 'string', 'max:250'], 'reason' => ['required', 'string', 'max:3000']];
        }
        if (str_ends_with($operation, '.decide')) {
            $rules += ['decision' => ['required', Rule::in(['approved', 'rejected', 'returned'])], 'comment' => ['required_unless:decision,approved', 'nullable', 'string', 'max:5000']];
            if ($operation === 'request.decide') {
                $rules += ['lines' => ['sometimes', 'array', 'max:100'], 'lines.*.line_id' => ['required', 'integer', 'distinct'], 'lines.*.approved_quantity' => $quantity];
            }
        }
        if (in_array($operation, ['request.cancel', 'purchase.cancel', 'issuance.return'], true)) {
            $rules += ['reason' => ['required', 'string', 'max:3000']];
        }
        if ($operation === 'purchase.approve') {
            $rules += ['comment' => ['nullable', 'string', 'max:5000']];
        }
        if ($operation === 'purchase.spend') {
            $rules += ['amount' => [...$money, 'gt:0'], 'document_reference' => ['required', 'string', 'max:250'], 'final_payment' => ['sometimes', 'boolean']];
        }
        if (in_array($operation, ['receipt.create', 'supply.receive', 'issuance.create', 'reservation.create'], true)) {
            $rules += ['warehouse_id' => ['required', 'exists:warehouses,id']];
        }
        if (in_array($operation, ['receipt.create', 'supply.receive'], true)) {
            $rules += ['delivery_reference' => ['required', 'string', 'max:250']];
        }
        if ($operation === 'issuance.create') {
            $rules += ['recipient' => ['required', 'string', 'max:250']];
        }
        if ($operation === 'reservation.create') {
            $rules += ['line_id' => ['required', 'integer'], 'quantity' => $quantity];
        }
        if (in_array($operation, ['receipt.create', 'supply.receive', 'issuance.create', 'issuance.return', 'count.encode'], true)) {
            $rules += ['lines' => ['required', 'array', 'min:1', 'max:1000'], 'lines.*.line_id' => ['required', 'integer', 'distinct']];
            if ($operation === 'count.encode') {
                $rules += ['lines.*.physical_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,4'], 'lines.*.variance_reason' => ['nullable', 'string', 'max:3000']];
            } else {
                $rules += ['lines.*.quantity' => $quantity];
            }
        }

        return $rules;
    }
}
