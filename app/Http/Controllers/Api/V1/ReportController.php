<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);

        $data = [];
        foreach (ReportService::TYPES as $key => [$title, $group, $description, $dates, $branch]) {
            $data[] = ['key' => $key, 'title' => $title, 'group' => $group, 'description' => $description, 'accepts_dates' => $dates, 'accepts_branch' => $branch];
        }

        return response()->json(['data' => $data]);
    }

    /** Query: from, to (YYYY-MM-DD), branch_id. Returns every row, with totals and chart series. */
    public function show(Request $request, string $type): JsonResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);
        abort_unless(isset(ReportService::TYPES[$type]), 404);

        $data = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'branch_id' => ['nullable', 'integer'],
        ])->validate();

        $from = $data['from'] ?? substr($this->reports->today(), 0, 8).'01';
        $to = $data['to'] ?? $this->reports->today();
        abort_if(now()->parse($from)->diffInDays(now()->parse($to)) > ReportService::MAX_DAYS, 422, 'Choose a period of at most '.ReportService::MAX_DAYS.' days.');

        return response()->json(['data' => $this->reports->run($type, $request->user(), ['from' => $from, 'to' => $to, 'branch_id' => $data['branch_id'] ?? null])]);
    }
}
