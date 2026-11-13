@extends('backend.layouts.app')

@section('title', __('Taxes & levies'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/feature-management.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/feature-management.css'))) }}">
@endpush

@push('scripts')
    <script src="{{ asset('backend/js/tax-rules.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/tax-rules.js'))) }}"></script>
@endpush

{{--
    The tax and levy rules. Every active rule for a transaction type whose
    scope covers the customer is charged on top of what they pay, each as its
    own line; with no rule nothing changes. The collected figure is in the
    site currency only, never a sum of different currencies.
--}}
@section('content')
    <x-page-header
        :title="__('Taxes & levies')"
        :subtitle="__('Rules that add a tax on top of what customers pay, shown as its own line on previews and receipts.')"
        icon="receipt"
        :breadcrumbs="[
            ['label' => __('Platform')],
            ['label' => __('Settings')],
            ['label' => __('Taxes & levies')],
        ]"
        :stats="[
            ['label' => __('Rules'), 'value' => (int) $stats['total'], 'tone' => 'brand', 'icon' => 'receipt'],
            ['label' => __('Active'), 'value' => (int) $stats['active'], 'tone' => $stats['active'] > 0 ? 'success' : 'neutral', 'icon' => 'check'],
            ['label' => __('Collected, last :days days', ['days' => \App\Http\Controllers\Backend\TaxRuleController::COLLECTED_DAYS]), 'value' => money($stats['collected'], $stats['currency'], false).' '.$stats['currency'], 'tone' => 'neutral', 'icon' => 'wallet-payment'],
        ]"
    >
        @can('tax-rule-manage')
            <x-slot:actions>
                <a href="{{ route('admin.tax-rule.create') }}" class="ph-btn ph-btn--primary" data-tr-create>
                    <x-icon name="plus" width="14" height="14"/>
                    {{ __('New rule') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    @php
        $canManage = auth('admin')->user()?->can('tax-rule-manage') ?? false;
    @endphp

    @if($needsHome)
        <div class="alert alert-warning" role="status" data-tr-needs-home>
            {{ __('Some rules apply to local or international customers, but no home country is set, so they apply to nobody. Set the home country in Site settings.') }}
        </div>
    @endif

    {{-- GF6 taxes six flows one at a time; the bulk flows deliberately carry no tax (CR7). --}}
    <div class="alert alert-info py-2 px-3 mb-3" role="note" data-tr-untaxed-bulk>
        {{ __('Rules apply to send money, withdrawals, deposits, exchange, remittance and bill payments. Mass payments and mass payouts are never taxed, even when a rule covers sending or withdrawing.') }}
    </div>

    <div class="card border-0 mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">{{ __('Tax and levy rules') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Rule') }}</th>
                            <th scope="col">{{ __('Applies to') }}</th>
                            <th scope="col">{{ __('Charge') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            @if($canManage)
                                <th scope="col" class="text-end">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rules as $rule)
                            @php
                                $ruleScope = $rule->scope();
                                $ruleCountries = implode(', ', array_map(fn (string $code): string => $countryNames[$code] ?? $code, $rule->countryCodes()));
                                $percent = rtrim(rtrim((string) $rule->percent, '0'), '.');
                                $fixed = \App\Support\DecimalMoney::compare((string) $rule->fixed_amount, '0') > 0 ? money((string) $rule->fixed_amount, $stats['currency'], false).' '.$stats['currency'] : null;
                            @endphp
                            <tr data-tr-rule="{{ $rule->id }}">
                                <td>
                                    <strong>{{ $rule->name }}</strong>
                                    <span class="d-block text-body-secondary small">{{ $rule->type()?->label() ?? $rule->transaction_type }}</span>
                                </td>
                                <td>
                                    {{ $ruleScope?->label() ?? $rule->scope }}
                                    @if($ruleScope === \App\Enums\Tax\TaxScope::Countries && $ruleCountries !== '')
                                        <span class="d-block text-body-secondary small">{{ $ruleCountries }}</span>
                                    @endif
                                </td>
                                <td dir="ltr">
                                    @if($rule->hasBrackets())
                                        @php
                                            $plain = fn (string $value): string => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
                                            $lower = '0';
                                        @endphp
                                        <span class="d-block">{{ __('By amount, :count brackets, on the :base', ['count' => count($rule->brackets()), 'base' => strtolower($rule->base()?->label() ?? (string) $rule->base)]) }}</span>
                                        <ul class="list-unstyled small text-body-secondary mb-0" data-tr-rule-brackets>
                                            @foreach($rule->brackets() as $bracket)
                                                @php
                                                    $parts = array_filter([
                                                        \App\Support\DecimalMoney::compare($bracket['percent'], '0') > 0 ? $plain($bracket['percent']).'%' : null,
                                                        \App\Support\DecimalMoney::compare($bracket['fixed'], '0') > 0 ? money($bracket['fixed'], $stats['currency'], false).' '.$stats['currency'] : null,
                                                    ]);
                                                @endphp
                                                <li>
                                                    {{ $bracket['up_to'] === null ? ($lower === '0' ? __('Any amount') : __('Above :from', ['from' => $plain($lower)])) :__(':from – :to', ['from' => $plain($lower), 'to' => $plain($bracket['up_to'])]) }}:
                                                    {{ $parts === [] ? __('no tax') : implode(' + ', $parts) }}
                                                </li>
                                                @php($lower = $bracket['up_to'] ?? $lower)
                                            @endforeach
                                        </ul>
                                    @elseif(\App\Support\DecimalMoney::compare((string) $rule->percent, '0') > 0)
                                        {{ $percent }}% {{ __('of the :base', ['base' => strtolower($rule->base()?->label() ?? (string) $rule->base)]) }}
                                    @endif
                                    @if($fixed !== null)
                                        <span class="d-block">+ {{ $fixed }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($canManage)
                                        <form action="{{ route('admin.tax-rule.toggle', $rule) }}" method="POST">
                                            @csrf
                                            <label class="form-check form-switch feature-mgmt-switch">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       data-tr-autosubmit
                                                       aria-label="{{ $rule->is_active ? __('Switch :name off', ['name' => $rule->name]) : __('Switch :name on', ['name' => $rule->name]) }}"
                                                       @checked($rule->is_active)>
                                            </label>
                                        </form>
                                    @else
                                        <span class="badge {{ $rule->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $rule->is_active ? __('Active') : __('Off') }}</span>
                                    @endif
                                </td>
                                @if($canManage)
                                    <td class="text-end">
                                        <div class="admin-table-actions">
                                            <a href="{{ route('admin.tax-rule.edit', $rule) }}" class="btn btn-outline-primary btn-sm admin-table-action" title="{{ __('Edit :name', ['name' => $rule->name]) }}">
                                                <x-icon name="edit" width="14" height="14"/>
                                                <span>{{ __('Edit') }}</span>
                                            </a>
                                            <form action="{{ route('admin.tax-rule.destroy', $rule) }}" method="POST" class="admin-table-actions__form"
                                                  data-tr-confirm="{{ __('Delete :name? Transactions already made keep the tax they were charged.', ['name' => $rule->name]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm admin-table-action admin-table-action--danger" title="{{ __('Delete :name', ['name' => $rule->name]) }}">
                                                    <x-icon name="trash" width="14" height="14"/>
                                                    <span>{{ __('Delete') }}</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 5 : 4 }}">
                                    <x-admin-not-found
                                        :title="__('No tax rules')"
                                        :message="__('Without a rule no tax is charged and every total stays as it is.')"
                                        icon="fa-receipt"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
