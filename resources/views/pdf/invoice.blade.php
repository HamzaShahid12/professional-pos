<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $sale['reference_no'] }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 22px; margin: 0 0 4px 0; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { vertical-align: top; padding: 0 0 12px 0; }
    .items th { background: #f3f4f6; text-align: left; padding: 6px; border-bottom: 1px solid #d1d5db; }
    .items td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
    .r { text-align: right; }
    .totals { width: 45%; margin-left: 55%; margin-top: 12px; }
    .totals td { padding: 3px 6px; }
    .grand td { font-weight: bold; font-size: 13px; border-top: 1px solid #1f2937; }
    .muted { color: #6b7280; }
    .void { color: #b91c1c; font-weight: bold; }
</style>
</head>
@php
    $money = fn ($v) => $symbol.' '.number_format((float) $v, $decimals);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
@endphp
<body>
<table class="meta">
    <tr>
        <td style="width:55%">
            <h1>{{ $business['legal_name'] ?: $business['name'] }}</h1>
            @if(!empty($business['address']))<div>{{ $business['address'] }}</div>@endif
            @if(!empty($business['phone']))<div>{{ $business['phone'] }}</div>@endif
            @if(!empty($business['email']))<div>{{ $business['email'] }}</div>@endif
            @if(!empty($business['tax_number']))<div>Tax no: {{ $business['tax_number'] }}</div>@endif
        </td>
        <td class="r">
            <h1>INVOICE</h1>
            <div><strong>{{ $sale['reference_no'] }}</strong></div>
            <div>Date: {{ $soldAt }}</div>
            @if($sale['branch'])<div>Branch: {{ $sale['branch'] }}</div>@endif
            @if($sale['cashier'])<div>Served by: {{ $sale['cashier'] }}</div>@endif
            @if($sale['status'] === 'voided')<div class="void">VOIDED</div>@endif
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <span class="muted">Bill to</span><br>
            @if($sale['customer'])
                <strong>{{ $sale['customer']['name'] }}</strong>
                @if(!empty($sale['customer']['address']))<br>{{ $sale['customer']['address'] }}@endif
                @if(!empty($sale['customer']['phone']))<br>{{ $sale['customer']['phone'] }}@endif
            @else
                Walk-in customer
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr><th>Item</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Discount</th><th class="r">Tax</th><th class="r">Total</th></tr>
    </thead>
    <tbody>
    @foreach($sale['items'] as $item)
        <tr>
            <td>{{ $item['name'] }}@if($item['sku'])<br><span class="muted">{{ $item['sku'] }}</span>@endif</td>
            <td class="r">{{ $qty($item['quantity']) }}</td>
            <td class="r">{{ $money($item['unit_price']) }}</td>
            <td class="r">{{ $money($item['discount']) }}</td>
            <td class="r">{{ $money($item['tax_amount']) }}</td>
            <td class="r">{{ $money($item['line_total']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="r">{{ $money($sale['subtotal']) }}</td></tr>
    <tr><td>Discount</td><td class="r">{{ $money($sale['discount_total']) }}</td></tr>
    <tr><td>Tax</td><td class="r">{{ $money($sale['tax_total']) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="r">{{ $money($sale['total']) }}</td></tr>
    <tr><td>Paid</td><td class="r">{{ $money($sale['paid_total']) }}</td></tr>
    @if((float) $sale['balance_due'] > 0)<tr><td>Balance due</td><td class="r">{{ $money($sale['balance_due']) }}</td></tr>@endif
</table>

@if(count($sale['payments']))
    <p class="muted" style="margin-top:14px">
        Payments:
        @foreach($sale['payments'] as $p){{ ucfirst(str_replace('_', ' ', $p['method'])) }} {{ $money($p['amount']) }}@if(!$loop->last), @endif @endforeach
    </p>
@endif
@if(!empty($sale['notes']))<p>Notes: {{ $sale['notes'] }}</p>@endif
@if(!empty($terms))<p class="muted"><strong>Terms</strong><br>{!! nl2br(e($terms)) !!}</p>@endif
@if(!empty($footer))<p style="text-align:center; margin-top:20px">{!! nl2br(e($footer)) !!}</p>@endif
</body>
</html>
