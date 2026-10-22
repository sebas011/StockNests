<?php

namespace App\Domain;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Monthly office activity, grouped by the date each submission or receipt occurred. */
final class OfficeMonthlyStatistics
{
    /** @return array<int, array<string, mixed>> */
    public static function rows(User $actor, ?int $fundId = null, ?int $year = null, ?string $dateFrom = null, ?string $dateTo = null, ?int $fiscalYearId = null): array
    {
        return iterator_to_array(self::iterate($actor, $fundId, $year, $dateFrom, $dateTo, $fiscalYearId), false);
    }

    /** @return iterable<int, array<string, mixed>> */
    public static function iterate(User $actor, ?int $fundId = null, ?int $year = null, ?string $dateFrom = null, ?string $dateTo = null, ?int $fiscalYearId = null): iterable
    {
        // These are office totals, so another staff member's submitted quantities
        // count even when the actor can only open their own request documents.
        $requests = DB::table('item_request_lines as lines')->join('item_requests as requests', 'requests.id', '=', 'lines.item_request_id')
            ->whereNotNull('requests.submitted_at')->whereNotIn('requests.status', ['draft', 'returned', 'rejected', 'canceled'])
            ->whereIn('requests.channel', ['supply', 'cash']);
        $supply = DB::table('supply_receipt_lines as lines')->join('supply_receipts as receipts', 'receipts.id', '=', 'lines.supply_receipt_id')
            ->join('item_requests as requests', 'requests.id', '=', 'receipts.item_request_id')->where('requests.channel', 'supply');
        $outside = DB::table('goods_receipt_lines as lines')->join('goods_receipts as receipts', 'receipts.id', '=', 'lines.goods_receipt_id')
            ->join('purchase_orders as orders', 'orders.id', '=', 'receipts.purchase_order_id')
            ->join('item_requests as requests', 'requests.id', '=', 'orders.item_request_id')->where('requests.channel', 'cash');

        $events = self::events($requests, $actor, 'requests.submitted_at', "CASE WHEN requests.channel = 'cash' THEN 'outside_quantity' ELSE 'requested_quantity' END", $fundId, $year, $dateFrom, $dateTo, $fiscalYearId)
            ->unionAll(self::events($supply, $actor, 'receipts.created_at', "'received_quantity'", $fundId, $year, $dateFrom, $dateTo, $fiscalYearId))
            ->unionAll(self::events($outside, $actor, 'receipts.created_at', "'outside_received_quantity'", $fundId, $year, $dateFrom, $dateTo, $fiscalYearId))
            ->orderBy('activity_month')->orderBy('funding_source_id')->orderBy('item_id')
            ->orderBy('activity_kind')->orderBy('activity_id');

        $currentKey = null;
        $row = null;
        // Sort in the database and sum one group at a time with exact decimals.
        // CSV exports never need to hold all monthly rows in memory.
        foreach ($events->lazy(200) as $event) {
            $key = $event->activity_month.':'.$event->funding_source_id.':'.$event->item_id;
            if ($key !== $currentKey) {
                if ($row !== null) {
                    yield $row;
                }
                $currentKey = $key;
                $row = [
                    'month' => $event->activity_month, 'item_id' => (int) $event->item_id,
                    'sku' => $event->sku, 'item' => $event->item, 'unit' => $event->unit,
                    'funding_source_id' => (int) $event->funding_source_id, 'fund' => $event->fund,
                    'requested_quantity' => '0.0000', 'received_quantity' => '0.0000',
                    'outside_quantity' => '0.0000', 'outside_received_quantity' => '0.0000',
                ];
            }
            $row[$event->activity_kind] = Decimal::add((string) $row[$event->activity_kind], (string) $event->quantity, 4);
        }
        if ($row !== null) {
            yield $row;
        }
    }

    /**
     * @param  literal-string  $dateColumn
     * @param  literal-string  $kind
     */
    private static function events(Builder $query, User $actor, string $dateColumn, string $kind, ?int $fundId, ?int $year, ?string $dateFrom, ?string $dateTo, ?int $fiscalYearId): Builder
    {
        $query->join('items', 'items.id', '=', 'lines.item_id')
            ->join('units_of_measure as units', 'units.id', '=', 'items.unit_of_measure_id')
            ->join('funding_sources as funds', 'funds.id', '=', 'requests.funding_source_id')
            ->where('requests.department_id', OfficeWorkflow::departmentId())
            ->whereIn('requests.funding_source_id', Access::fundIds($actor))
            ->select('items.id as item_id', 'items.sku', 'items.name as item', 'units.name as unit', 'funds.id as funding_source_id', 'funds.code as fund', 'lines.id as activity_id')
            ->selectRaw('SUBSTR(CAST('.$dateColumn.' AS TEXT), 1, 7) AS activity_month, CAST(lines.quantity AS TEXT) AS quantity, '.$kind.' AS activity_kind');
        if ($fundId !== null) {
            $query->where('requests.funding_source_id', $fundId);
        }
        if ($fiscalYearId !== null) {
            $query->where('requests.fiscal_year_id', $fiscalYearId);
        }
        if ($year !== null) {
            $query->whereYear($dateColumn, $year);
        }
        if ($dateFrom !== null) {
            $query->whereDate($dateColumn, '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate($dateColumn, '<=', $dateTo);
        }

        return $query;
    }
}
