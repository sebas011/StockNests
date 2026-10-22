<?php

namespace App\Domain;

use App\Models\AnnualProjection;
use App\Models\Department;
use App\Models\ItemRequestLine;
use App\Models\ProjectionLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Office identity and the annual Supply allowance, separate from stock on hand. */
final class OfficeWorkflow
{
    public const NAME = 'Office of the Vice President for Academic Affairs';

    public const CODE = 'OVPAA';

    public const DEPARTMENT_CODE = self::CODE;

    public static function enabled(): bool
    {
        return (bool) config('stocknest.office_workflow', true);
    }

    public static function departmentId(): int
    {
        return (int) Department::firstOrCreate(['code' => self::CODE], ['name' => self::NAME, 'is_active' => true])->id;
    }

    /** @return Builder<AnnualProjection> */
    public static function currentPlans(): Builder
    {
        return AnnualProjection::where('department_id', self::departmentId())->where('status', 'approved')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('annual_projections as newer')
                ->where('newer.status', 'approved')
                ->whereRaw('COALESCE(newer.original_projection_id, newer.id) = COALESCE(annual_projections.original_projection_id, annual_projections.id)')
                ->whereColumn('newer.revision', '>', 'annual_projections.revision');
        });
    }

    /** @return array<int, array<string, mixed>> */
    public static function allowanceRows(User $actor, ?int $fundId = null, ?int $yearId = null): array
    {
        return iterator_to_array(self::allowances($actor, $fundId, $yearId), false);
    }

    /** @return array<int, array<string, mixed>> */
    public static function monthlyRows(User $actor, ?int $fundId = null, ?int $year = null): array
    {
        return OfficeMonthlyStatistics::rows($actor, $fundId, $year);
    }

    /** @return iterable<int, array<string, mixed>> */
    public static function allowances(User $actor, ?int $fundId = null, ?int $yearId = null): iterable
    {
        $plans = self::currentPlans()->whereIn('funding_source_id', Access::fundIds($actor))
            ->when($fundId !== null, fn ($q) => $q->where('funding_source_id', $fundId))
            ->when($yearId !== null, fn ($q) => $q->where('fiscal_year_id', $yearId));
        foreach (ProjectionLine::whereIn('annual_projection_id', $plans->select('id'))->with('projection.fund', 'projection.fiscalYear', 'item.unit')->lazyById(200) as $line) {
            $plan = $line->projection;
            // Office totals include all office staff requests, even when a requester
            // can only open their own request documents.
            $used = ItemRequestLine::where('item_id', $line->item_id)->whereHas('request', fn ($q) => $q
                ->where('funding_source_id', $plan->funding_source_id)->where('fiscal_year_id', $plan->fiscal_year_id)
                ->where('department_id', $plan->department_id)->where('channel', 'supply')->whereNotNull('submitted_at')
                ->whereNotIn('status', ['draft', 'returned', 'rejected', 'canceled']));
            $requested = Decimal::add((string) (clone $used)->sum('quantity'), '0', 4);
            $received = Decimal::add((string) (clone $used)->sum('received_quantity'), '0', 4);
            yield [
                'item_id' => $line->item_id, 'sku' => $line->item->sku, 'item' => $line->item->name, 'unit' => $line->item->unit->name,
                'fund' => $plan->fund->code, 'year' => $plan->fiscalYear->year, 'funding_source_id' => $plan->funding_source_id,
                'fiscal_year_id' => $plan->fiscal_year_id, 'department_id' => $plan->department_id,
                'approved_quantity' => $line->quantity, 'requested_quantity' => $requested, 'received_quantity' => $received,
                'remaining_quantity' => Decimal::sub($line->quantity, $requested, 4),
                'pending_quantity' => Decimal::sub($requested, $received, 4),
            ];
        }
    }
}
