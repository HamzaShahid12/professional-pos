<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrencyRequest;
use App\Http\Requests\ExchangeRateRequest;
use App\Models\Currency;
use App\Services\CurrencyService;
use App\Support\CurrencyCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function __construct(private CurrencyService $currencies) {}

    public function index(): Response
    {
        $this->authorize('create', Currency::class); // currencies.manage

        return Inertia::render('Settings/Currencies', [
            'currencies' => Currency::query()->with(['rates' => fn ($q) => $q->limit(8)])->orderByDesc('is_base')->orderBy('code')->get()
                ->map(fn ($c) => $c->only('id', 'code', 'name', 'symbol', 'decimal_places', 'exchange_rate', 'is_base', 'is_active') + [
                    'history' => $c->rates->map(fn ($r) => ['rate' => (string) $r->rate, 'effective_at' => $r->effective_at?->toIso8601String(), 'source' => $r->source])->values(),
                ]),
            'catalog' => collect(CurrencyCatalog::all())->map(fn ($v, $k) => ['code' => $k] + $v)->values(),
        ]);
    }

    public function store(CurrencyRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $request) {
            $currency = Currency::create([
                'code' => $data['code'], 'name' => $data['name'], 'symbol' => $data['symbol'],
                'decimal_places' => $data['decimal_places'], 'exchange_rate' => 1, 'is_base' => false, 'is_active' => $data['is_active'] ?? true,
            ]);
            $this->currencies->setRate($currency, (string) $data['exchange_rate'], null, $request->user(), 'initial');
        });

        return back()->with('success', 'Currency added.');
    }

    public function update(CurrencyRequest $request, Currency $currency): RedirectResponse
    {
        $data = $request->validated();
        if ($currency->is_base) {
            unset($data['is_active']);
        }
        $currency->update($data);

        return back()->with('success', 'Currency updated.');
    }

    public function storeRate(ExchangeRateRequest $request, Currency $currency): RedirectResponse
    {
        $at = $request->filled('effective_at') ? Carbon::parse($request->input('effective_at')) : null;
        $this->currencies->setRate($currency, (string) $request->input('rate'), $at, $request->user());

        return back()->with('success', 'Exchange rate recorded. Previous rates are kept in history.');
    }
}
