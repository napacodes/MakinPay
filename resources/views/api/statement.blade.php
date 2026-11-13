<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0B1633;margin:24px}
h1{font-size:18px;margin:0 0 4px}
.muted{color:#5F6C86}
table{width:100%;border-collapse:collapse;margin-top:16px}
th,td{padding:6px 8px;border-bottom:1px solid #E3E8F2;text-align:left;vertical-align:top}
th{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#5F6C86}
td.num,th.num{text-align:right;white-space:nowrap}
.out{color:#D1343A}.in{color:#0B8A4C}
</style>
</head>
<body>
<h1>{{ config('app.name') }} — {{ $heading['title'] }}</h1>
<div class="muted">{{ $heading['name'] }} · {{ $heading['wallet'] }} · {{ $heading['currency'] }}</div>
@if(!empty($heading['closing']))
<div class="muted">{{ __('Closing balance') }}: {{ $heading['closing'] }} {{ $heading['currency'] }}</div>
@endif
<table>
<thead><tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Description') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Amount') }}</th><th class="num">{{ __('Fee') }}</th></tr></thead>
<tbody>
@foreach($rows as $row)
<tr>
<td>{{ $row->created_at?->format('Y-m-d H:i') }}</td>
<td>{{ $row->trx_id }}</td>
<td>{{ $row->description }}</td>
<td>{{ $row->status?->value }}</td>
<td class="num {{ $row->amount_flow?->value === 'minus' ? 'out' : 'in' }}">{{ $row->amount_flow?->value === 'minus' ? '-' : '+' }}{{ \App\Support\ApiMoney::amount($row->amount, $row->currency) }} {{ $row->currency }}</td>
<td class="num">{{ \App\Support\ApiMoney::amount($row->fee, $row->currency) }}</td>
</tr>
@endforeach
</tbody>
</table>
</body>
</html>
