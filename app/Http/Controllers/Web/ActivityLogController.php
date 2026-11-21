<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): Response
    {
        abort_unless($request->user()->can('audit.view'), 403);
        $search = trim((string) $request->query('search', ''));

        return Inertia::render('ActivityLogs/Index', [
            'logs' => ActivityLog::query()->with('user:id,name')->where('business_id', $tenant->businessId())
                ->when($search !== '', fn ($q) => $q->where('action', 'like', "%{$search}%"))
                ->latest('created_at')->latest('id')->paginate(25)->withQueryString()
                ->through(fn ($l) => [
                    'id' => $l->id, 'action' => $l->action, 'user' => $l->user?->name, 'ip' => $l->ip_address,
                    'at' => $l->created_at?->toIso8601String(),
                ]),
            'filters' => ['search' => $search],
        ]);
    }
}
