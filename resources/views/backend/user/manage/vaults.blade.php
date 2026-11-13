@extends('backend.user.manage')

@section('user_manage_content')
    @php
        /*
         * A translated sentence with figures in it. Every figure - an amount,
         * a date - is escaped and isolated, so an Arabic line cannot reorder
         * "31 Dec 2026" or "0.0001 BTC" into the words around it. The sentence
         * itself is escaped too; $words are plain text that reads with it.
         */
        $sentence = function (string $key, array $figures = [], array $words = []): string {
            $marks = [];
            $slots = [];
            foreach (array_keys($figures) as $index => $name) {
                $slot         = "\u{E000}{$index}\u{E001}";
                $slots[$name] = $slot;
                $marks[$slot] = '<bdi dir="ltr">'.e($figures[$name]).'</bdi>';
            }

            return strtr(e(__($key, $slots + $words)), $marks);
        };
    @endphp

    {{-- Read-only. Vault money left the customer's wallets, so the wallets
         rail beside this does not count it; this is where it is seen. --}}
    <section class="um-card" data-um-vaults>
        <div class="um-card__head">
            <h2 class="um-card__title">{{ __('Savings vaults') }}</h2>
            <span class="um-card__aside">{{ __('Open first') }}</span>
        </div>

        @forelse($vaults as $vault)
            @php
                /* Two short lines rather than one long one: the list's sub line
                   ends in an ellipsis, and the rule and dates were what it cut. */
                $goal = $vault['target'] === null
                    ? [e(__('No target'))]
                    : [
                        $sentence('Target :amount', ['amount' => $vault['target']])
                            .($vault['target_date'] ? ' '.$sentence('by :date', ['date' => $vault['target_date']]) : ''),
                        e(__(':percent% of target', ['percent' => $vault['progress']])),
                    ];
                $goal[] = $sentence('Opened :date', ['date' => $vault['opened']]);

                $plan = [];
                if ($vault['auto'] !== null) {
                    $plan[] = $sentence(
                        $vault['auto']['frequency'] === 'weekly' ? ':amount every :day' : ':amount monthly on day :day',
                        ['amount' => $vault['auto']['amount']],
                        ['day' => $vault['auto']['day']],
                    );
                }
                if ($vault['round_up'] !== null) {
                    $plan[] = $sentence('Round-ups to :unit', ['unit' => $vault['round_up']]);
                }
                if ($plan === []) {
                    $plan[] = e(__('Manual only'));
                }
                if ($vault['next'] !== null) {
                    $plan[] = $sentence('Next :date', ['date' => $vault['next']]);
                }
            @endphp
            <div class="um-list__row" data-um-vault>
                <span class="um-list__copy">
                    <span class="um-list__title">{{ $vault['name'] }} · <span class="um-mono" dir="ltr">{{ $vault['code'] ?? '—' }}</span></span>
                    <span class="um-list__sub" data-um-vault-goal>{!! implode(' · ', $goal) !!}</span>
                    <span class="um-list__sub" data-um-vault-plan>{!! implode(' · ', $plan) !!}</span>
                </span>
                <span class="um-list__end">
                    <span class="um-badge um-badge--{{ $vault['tone'] }}">{{ $vault['status_label'] }}</span>
                    <span class="um-amount" dir="ltr">{{ $vault['balance'] }}</span>
                </span>
            </div>
        @empty
            <div class="um-card__empty">{{ __('This user has not opened any savings vaults yet.') }}</div>
        @endforelse

        @if($vaults->hasPages())
            <div class="um-pager">
                <span>{{ __('Showing :from–:to of :total', ['from' => $vaults->firstItem(), 'to' => $vaults->lastItem(), 'total' => $vaults->total()]) }}</span>
                <div class="um-pager__nav">
                    <a class="um-btn um-btn--sm" href="{{ $vaults->previousPageUrl() ?? '#' }}" @if($vaults->onFirstPage()) aria-disabled="true" @endif>{{ __('Previous') }}</a>
                    <a class="um-btn um-btn--sm" href="{{ $vaults->nextPageUrl() ?? '#' }}" @unless($vaults->hasMorePages()) aria-disabled="true" @endunless>{{ __('Next') }}</a>
                </div>
            </div>
        @endif
    </section>
@endsection
