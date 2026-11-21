<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrencyRequest;
use App\Http\Requests\ExchangeRateRequest;
use App\Http\Resources\Api\V1\CurrencyResource;
use App\Models\Currency;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CurrencyController extends Controller
{
    public function __construct(private CurrencyService $currencies) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Currency::class);

        return CurrencyResource::collection(Currency::query()->with(['rates' => fn ($q) => $q->limit(10)])->orderByDesc('is_base')->orderBy('code')->get());
    }

    public function store(CurrencyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $currency = DB::transaction(function () use ($data, $request) {
            $currency = Currency::create([
                'code' => $data['code'], 'name' => $data['name'], 'symbol' => $data['symbol'],
                'decimal_places' => $data['decimal_places'], 'exchange_rate' => 1,
                'is_base' => false, 'is_active' => $data['is_active'] ?? true,
            ]);
            $this->currencies->setRate($currency, (string) $data['exchange_rate'], null, $request->user(), 'initial');

            return $currency->refresh();
        });

        return (new CurrencyResource($currency))->response()->setStatusCode(201);
    }

    public function update(CurrencyRequest $request, Currency $currency): CurrencyResource
    {
        $data = $request->validated();
        if ($currency->is_base) {
            unset($data['is_active']); // the base currency can never be deactivated
        }
        $currency->update($data);

        return new CurrencyResource($currency);
    }

    /** Append a new exchange rate. History is kept; transactions keep their own snapshot. */
    public function storeRate(ExchangeRateRequest $request, Currency $currency): CurrencyResource
    {
        $at = $request->filled('effective_at') ? Carbon::parse($request->input('effective_at')) : null;
        $this->currencies->setRate($currency, (string) $request->input('rate'), $at, $request->user());

        return new CurrencyResource($currency->refresh()->load(['rates' => fn ($q) => $q->limit(10)]));
    }
}
