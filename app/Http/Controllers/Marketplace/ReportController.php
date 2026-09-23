<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\UsageReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Usage report — who downloaded what, over a date range.
 *
 * The aggregation lives in App\Services\Marketplace\UsageReportService; this
 * controller only turns a request into filters and the result into a page or a
 * CSV. Both routes sit inside the `can:downloads.view` group in routes/web.php,
 * which is the only permission check they need.
 */
class ReportController extends Controller
{
    public function __construct(private UsageReportService $reports) {}

    public function usage(Request $request)
    {
        $filters = $this->reports->filters($request);

        return Inertia::render('Marketplace/Reports/Usage', [
            'filters' => $filters,
            'totals' => $this->reports->totals($filters),
            'customers' => $this->reports->byCustomer($filters),
            'entries' => $this->reports->byEntry($filters),
        ]);
    }

    /**
     * One report section as CSV. `section=customers` or `section=entries`; the
     * rows are exactly the ones the page is showing for the same query string.
     */
    public function usageExport(Request $request): StreamedResponse
    {
        $filters = $this->reports->filters($request);
        $section = $request->string('section')->toString() === 'entries' ? 'entries' : 'customers';

        ['columns' => $columns, 'rows' => $rows] = $this->reports->section($section, $filters);

        $filename = 'usage-'.$section.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($rows as $row) {
                fputcsv($handle, $this->reports->csvRow($row, $columns));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
