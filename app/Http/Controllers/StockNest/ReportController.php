<?php

namespace App\Http\Controllers\StockNest;

use App\Domain\Access;
use App\Domain\WorkflowService;
use App\Http\Controllers\Controller;
use App\Reports\StockNestReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use Symfony\Component\HttpFoundation\Response;

final class ReportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, WorkflowService $workflow): Response
    {
        Access::assert($request->user(), 'reports.view');
        if ($report === 'audit') {
            Access::assert($request->user(), 'audit.view');
        }
        $request->validate([
            'fund_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'fiscal_year_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        if ($request->filled('fund_id')) {
            abort_unless(in_array($request->integer('fund_id'), Access::fundIds($request->user()), true), 403);
        }
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);
        $source = (new StockNestReport($request, $workflow))->rows($report);
        $filename = 'stocknest-'.$report.'-'.now()->format('Ymd-His').'.'.$format;
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($source): void {
                $handle = fopen('php://output', 'w');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot open the report output stream.');
                }
                $hasRows = false;
                try {
                    foreach ($source as $row) {
                        if (! $hasRows) {
                            fputcsv($handle, array_keys($row), ',', '"', '');
                            $hasRows = true;
                        }
                        fputcsv($handle, array_map($this->csvValue(...), array_values($row)), ',', '"', '');
                    }
                    if (! $hasRows) {
                        fputcsv($handle, ['No matching records'], ',', '"', '');
                    }
                } finally {
                    fclose($handle);
                }
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Bound expanded lines and bytes before constructing a workbook or PDF.
        $maxRows = max(1, (int) config('stocknest.reports.'.$format.'_max_rows', $format === 'pdf' ? 1000 : 10000));
        $maxBytes = max(1, (int) config('stocknest.reports.'.$format.'_max_bytes', $format === 'pdf' ? 5000000 : 20000000));
        $rows = [];
        $bytes = 0;
        foreach ($source as $row) {
            $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
            if (count($rows) >= $maxRows || $bytes > $maxBytes) {
                $message = 'This report is too large for '.$format.'. Narrow the filters or export CSV.';
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return response()->view('reports.too-large', [
                    'message' => $message,
                    'csvUrl' => route('stocknest.export', ['report' => $report, 'format' => 'csv'] + $request->query()),
                ], 422);
            }
            $rows[] = $row;
        }
        $headings = count($rows) ? array_keys($rows[0]) : ['No matching records'];
        if ($format === 'xlsx') {
            // Text binding preserves exact decimals and prevents spreadsheet formulas.
            $export = new class(array_map(array_values(...), $rows), $headings) extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
            {
                /** @param list<list<mixed>> $rows
                 * @param  list<string>  $columns
                 */
                public function __construct(private array $rows, private array $columns) {}

                /** @return list<list<mixed>> */
                public function array(): array
                {
                    return $this->rows;
                }

                /** @return list<string> */
                public function headings(): array
                {
                    return $this->columns;
                }
            };

            return Excel::download($export, $filename);
        }

        $escape = fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#172c2c}h1{font-size:18px}table{border-collapse:collapse;width:100%}th,td{padding:5px;border:1px solid #ccd6d6;text-align:left;overflow-wrap:anywhere}th{background:#e8f1ee}thead{display:table-header-group}</style></head><body><h1>StockNest · '.$escape(ucwords(str_replace('-', ' ', $report))).'</h1><p>Generated '.$escape(now()->timezone('Asia/Manila')->format('Y-m-d H:i T')).' · '.$escape($request->user()->name).' · PHP</p><p>Filters: '.$escape(http_build_query($request->query())).'</p><table><thead><tr>';
        foreach ($headings as $heading) {
            $html .= '<th>'.$escape(ucwords(str_replace('_', ' ', $heading))).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.$escape($value).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return Pdf::loadHTML($html)->setPaper('a3', 'landscape')->download($filename);
    }

    private function csvValue(mixed $value): mixed
    {
        if (is_string($value) && ! is_numeric($value) && preg_match('/^[=+\-@]/', preg_replace('/^[\x00-\x20]+/', '', $value))) {
            return "'".$value;
        }

        return $value;
    }
}
