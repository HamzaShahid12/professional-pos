<?php

namespace App\Http\Controllers\Web;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const SCREEN_ROWS = 500;

    public function __construct(private ReportService $reports) {}

    public function index(Request $request): Response
    {
        $groups = [];
        foreach (ReportService::TYPES as $key => [$title, $group, $description]) {
            $groups[$group][] = ['key' => $key, 'title' => $title, 'description' => $description];
        }

        return Inertia::render('Reports/Index', ['groups' => $groups]);
    }

    public function show(Request $request, string $type): Response
    {
        $filters = $this->filters($request, $type);
        $report = $this->reports->run($type, $request->user(), $filters, self::SCREEN_ROWS);
        [, , , $usesDates, $usesBranch] = ReportService::TYPES[$type];

        return Inertia::render('Reports/Show', [
            'type' => $type, 'report' => $report,
            'filters' => ['from' => $filters['from'], 'to' => $filters['to'], 'branch_id' => $filters['branch_id'] ?? null],
            'uses_dates' => $usesDates, 'uses_branch' => $usesBranch,
            'branches' => $usesBranch ? Branch::query()->whereIn('id', $request->user()->accessibleBranchIds($this->tenantId()))->orderBy('name')->get(['id', 'name']) : [],
            'can_export' => $request->user()->can('reports.export'),
            'generated_at' => now($this->reports->tz())->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Every row (the screen shows 500). `format=xlsx` gives an Excel workbook; the default is a CSV that opens
     * cleanly in Excel (UTF-8 with a byte-order mark).
     */
    public function export(Request $request, string $type): mixed
    {
        $report = $this->reports->run($type, $request->user(), $this->filters($request, $type));
        $stamp = $type.'-'.now($this->reports->tz())->format('Ymd-His');

        if ($request->query('format') === 'xlsx') {
            return Excel::download(new ReportExport($report), $stamp.'.xlsx');
        }

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($report['columns'], 'label'));
            foreach ($report['rows'] as $row) {
                fputcsv($out, $this->csvRow($report['columns'], $row));
            }
            if ($report['totals']) {
                fputcsv($out, $this->csvRow($report['columns'], $report['totals']));
            }
            fclose($out);
        }, $stamp.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvRow(array $columns, array $row): array
    {
        return array_map(function ($c) use ($row) {
            $v = (string) ($row[$c['key']] ?? '');
            // A text cell that starts with = + - @ would run as a formula in a spreadsheet. Numbers are left alone.
            return $c['type'] === 'text' && $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v;
        }, $columns);
    }

    private function filters(Request $request, string $type): array
    {
        abort_unless(isset(ReportService::TYPES[$type]), 404);

        $data = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'branch_id' => ['nullable', 'integer'], 'format' => ['nullable', 'in:csv,xlsx'],
        ])->validate();

        $from = $data['from'] ?? substr($this->reports->today(), 0, 8).'01';
        $to = $data['to'] ?? $this->reports->today();
        abort_if(now()->parse($from)->diffInDays(now()->parse($to)) > ReportService::MAX_DAYS, 422, 'Choose a period of at most '.ReportService::MAX_DAYS.' days.');

        return ['from' => $from, 'to' => $to, 'branch_id' => $data['branch_id'] ?? null];
    }

    private function tenantId(): int
    {
        return app(\App\Support\TenantContext::class)->businessId();
    }
}
