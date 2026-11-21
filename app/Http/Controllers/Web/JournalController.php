<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\JournalRequest;
use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    /** Where the document behind an automatic entry can be opened. */
    private const LINKS = ['sale' => '/sales/', 'purchase' => '/purchases/', 'stock_adjustment' => '/inventory/adjustments/', 'stock_count' => '/inventory/counts/'];

    public function __construct(private JournalService $journal, private ChartOfAccounts $chart, private PostingService $posting) {}

    public function index(Request $request): Response
    {
        $f = $request->only('search', 'kind', 'account_id', 'from', 'to');
        $like = fn ($t) => '%'.addcslashes($t, '%_\\').'%';

        $rows = JournalEntry::query()->with('author:id,name')
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('entry_no', 'like', $like($t))->orWhere('description', 'like', $like($t))))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['account_id'] ?? null, fn ($q, $v) => $q->whereIn('id', \App\Models\JournalLine::query()->where('account_id', $v)->select('journal_entry_id')))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('entry_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('entry_date', '<=', $v))
            ->orderByDesc('entry_date')->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn ($e) => [
                'id' => $e->id, 'entry_no' => $e->entry_no, 'entry_date' => $e->entry_date?->toDateString(), 'description' => $e->description,
                'kind' => $e->kind, 'total' => $e->total, 'user' => $e->author?->name,
            ]);

        return Inertia::render('Accounting/Journal', [
            'entries' => $rows, 'filters' => $f,
            'accounts' => Account::query()->orderBy('code')->get(['id', 'code', 'name']),
            'can_manage' => $request->user()->can('accounting.manage'),
        ]);
    }

    public function create(): Response
    {
        $this->chart->ensure();
        $locked = array_map(fn ($k) => $this->chart->id($k), ['receivable', 'payable', 'inventory']);

        return Inertia::render('Accounting/JournalForm', [
            'accounts' => Account::query()->where('is_active', true)->whereNotIn('id', $locked)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'today' => $this->posting->today(), 'lock_date' => $this->journal->lockDate(),
        ]);
    }

    public function store(JournalRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $entry = $this->journal->manual($data['entry_date'], $data['description'], array_map(fn ($l) => [
            'account' => (int) $l['account_id'], 'debit' => $l['debit'] ?? '0', 'credit' => $l['credit'] ?? '0', 'memo' => $l['memo'] ?? null,
        ], $data['lines']));

        return redirect()->route('accounting.journal.show', $entry)->with('success', "Journal {$entry->entry_no} posted.");
    }

    public function show(Request $request, JournalEntry $entry): Response
    {
        $entry->load(['lines.account:id,code,name', 'author:id,name', 'reversal:id,entry_no,reverses_id', 'reverses:id,entry_no']);

        return Inertia::render('Accounting/JournalShow', [
            'entry' => [
                'id' => $entry->id, 'entry_no' => $entry->entry_no, 'entry_date' => $entry->entry_date?->toDateString(), 'description' => $entry->description,
                'kind' => $entry->kind, 'total' => $entry->total, 'user' => $entry->author?->name, 'created_at' => $entry->created_at?->toIso8601String(),
                'reversal' => $entry->reversal ? ['id' => $entry->reversal->id, 'entry_no' => $entry->reversal->entry_no] : null,
                'reverses' => $entry->reverses ? ['id' => $entry->reverses->id, 'entry_no' => $entry->reverses->entry_no] : null,
                'source_url' => isset(self::LINKS[$entry->source_type]) && $entry->source_id ? self::LINKS[$entry->source_type].$entry->source_id : null,
                'lines' => $entry->lines->map(fn ($l) => [
                    'id' => $l->id, 'account_id' => $l->account_id, 'account' => $l->account ? $l->account->code.' · '.$l->account->name : '—',
                    'debit' => $l->debit, 'credit' => $l->credit, 'memo' => $l->memo,
                ])->all(),
            ],
            'can_reverse' => $request->user()->can('accounting.manage') && $entry->kind === 'manual' && ! $entry->reversal,
        ]);
    }

    public function reverse(Request $request, JournalEntry $entry): RedirectResponse
    {
        abort_unless($request->user()->can('accounting.manage'), 403);
        if ($entry->kind !== 'manual') {
            throw ValidationException::withMessages(['entry' => 'Automatic entries are reversed by their document: void the sale, return the goods, or post an adjustment.']);
        }
        $data = $request->validate(['entry_date' => ['required', 'date_format:Y-m-d']]);
        $reversal = $this->journal->reverse($entry, $data['entry_date']);

        return redirect()->route('accounting.journal.show', $reversal)->with('success', "Reversed {$entry->entry_no} with {$reversal->entry_no}.");
    }
}
