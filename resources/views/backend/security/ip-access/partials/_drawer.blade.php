@php
    /*
     * One address, in full. Four states share this panel:
     *
     *   refusing  — an automatic block still inside its window
     *   permanent — an administrator wrote it; the guard never touches it
     *   lapsed    — it ran out and the address is arriving again
     *   held back — it passed the threshold and was deliberately NOT
     *               blocked, so there is no row at all
     */
    $heldBack  = $block === null;
    $lapsed    = $block?->isExpired() ?? false;
    $permanent = $block !== null && $block->expires_at === null;
    $automatic = $block?->isAutomatic() ?? false;

    $blockWindow = $block?->windowMinutes();
    $left        = $block?->minutesRemaining();

    $elapsed = $blockWindow !== null && $left !== null
        ? max(0, min(100, (int) round((($blockWindow - $left) / max(1, $blockWindow)) * 100)))
        : 0;

    $tone = match (true) {
        $heldBack => 'calm',
        $lapsed   => 'done',
        default   => 'live',
    };

    /*
     * Signals, heaviest contribution first — the order an operator reads
     * them in, not the order they happened.
     */
    $breakdown = collect($signals)
        ->map(fn ($count, $key) => [
            'key'      => $key,
            'count'    => (int) $count,
            'weight'   => $weights[$key] ?? 1,
            'subtotal' => (int) $count * ($weights[$key] ?? 1),
        ])
        ->sortByDesc('subtotal')
        ->values();

    $names = [
        'login_lockout'   => __('Sign-in limiter tripped'),
        'throttled'       => __('Rate limit hit'),
        'forbidden'       => __('Pages it may not have'),
        'unauthenticated' => __('Unauthenticated calls to protected endpoints'),
        'csrf'            => __('Missing or forged CSRF token'),
    ];

    $sharedLimit = (int) config('security.ip_auto_block_shared_accounts', 3);
@endphp

<div class="ipa-panel">
    <div class="ipa-panel__head">
        <div class="ipa-panel__ident">
            <div>
                <span class="ipa-panel__eyebrow">
                    {{ $heldBack ? __('Under watch') : __('Blocked address') }}
                </span>
                <span class="ipa-panel__addr" dir="ltr">{{ $ip }}</span>
            </div>
            <button type="button" class="ipa-panel__close" data-ipa-close aria-label="{{ __('Close') }}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12"></path><path d="M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="ipa-panel__chips">
            @if($heldBack)
                <span class="ipa-chip"><x-icon name="user-group" width="10" height="10"/>{{ __('Shared') }}</span>
            @elseif($automatic)
                <span class="ipa-chip ipa-chip--auto"><x-icon name="barrier-block" width="10" height="10"/>{{ __('Automatic') }}</span>
            @else
                <span class="ipa-chip ipa-chip--manual"><x-icon name="users" width="10" height="10"/>{{ __('By admin') }}</span>
            @endif

            @foreach($location as $part)
                <span class="ipa-chip ipa-chip--plain">{{ $part }}</span>
            @endforeach
        </div>
    </div>

    <div class="ipa-panel__main">

        {{-- The verdict and why it was reached are one card, not two sections. --}}
        <div class="ipa-verdict {{ $tone === 'calm' ? 'ipa-verdict--calm' : ($tone === 'done' ? 'ipa-verdict--done' : '') }}">
            <div class="ipa-verdict__line">
                <span class="ipa-verdict__state">
                    <span class="ipa-verdict__dot" aria-hidden="true"></span>
                    @if($heldBack)
                        {{ __('Held back, not blocked') }}
                    @elseif($lapsed)
                        {{ __('Lapsed on its own') }}
                    @elseif($permanent)
                        {{ __('Refusing traffic, permanently') }}
                    @else
                        {{ __('Refusing traffic now') }}
                    @endif
                </span>

                @if(! $heldBack && ! $lapsed && ! $permanent)
                    <span class="ipa-verdict__left"><bdi>{{ trans_choice('{1} :count minute left|[2,*] :count minutes left', $left, ['count' => $left]) }}</bdi></span>
                @endif
            </div>

            @if(! $heldBack && ! $lapsed && ! $permanent)
                <span class="ipa-verdict__track" aria-hidden="true">
                    <span class="ipa-verdict__fill" style="inline-size: {{ $elapsed }}%"></span>
                </span>
            @endif

            <p>
                @if($heldBack)
                    {{ __(':strikes weighted strikes inside :window minutes, past the threshold of :threshold. It was not blocked because several accounts sign in from here, so the decision is yours.', ['strikes' => $strikes, 'window' => $window, 'threshold' => $threshold]) }}
                @elseif($permanent)
                    {{ $block->reason ?: __('No reason recorded.') }}
                    {{ __('You wrote this one, so it never lapses and the guard never rewrites it.') }}
                @elseif($lapsed)
                    {{ __(':strikes weighted strikes earned a :window minute block at :from. It lapsed at :to, and the address has been arriving again since.', ['strikes' => $strikes, 'window' => $blockWindow, 'from' => $block->blocked_at?->format('H:i'), 'to' => $block->expires_at?->format('H:i')]) }}
                @else
                    {{ __(':strikes weighted strikes, past the threshold of :threshold. Blocked for :window minutes at :from; it lapses at :to with nobody doing anything, and a repeat doubles it.', ['strikes' => $strikes, 'threshold' => $threshold, 'window' => $blockWindow, 'from' => $block->blocked_at?->format('H:i'), 'to' => $block->expires_at?->format('H:i')]) }}
                @endif
            </p>
        </div>

        @if($breakdown->isNotEmpty())
            <div class="ipa-section">
                <div class="ipa-section__head">
                    <span class="ipa-label">{{ __('What it did') }}</span>
                    <span class="ipa-section__aside">{{ __('Weighted') }}</span>
                </div>

                <div class="ipa-tl">
                    @foreach($breakdown as $entry)
                        <div class="ipa-tl__item">
                            <span class="ipa-tl__rail">
                                <span class="ipa-tl__dot {{ $entry['weight'] >= 5 ? 'ipa-tl__dot--heavy' : ($entry['weight'] >= 3 ? 'ipa-tl__dot--mid' : '') }}"></span>
                                <span class="ipa-tl__line"></span>
                            </span>
                            <span class="ipa-tl__what">
                                <span class="ipa-tl__name">{{ $names[$entry['key']] ?? $entry['key'] }}</span>
                                <span class="ipa-tl__meta">
                                    {{ trans_choice('{1} once|[2,*] :count times', $entry['count'], ['count' => $entry['count']]) }}
                                    · {{ __('weight :weight', ['weight' => $entry['weight']]) }}
                                </span>
                            </span>
                            <span class="ipa-tl__score" dir="ltr">{{ $entry['subtotal'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="ipa-fact {{ $shared ? 'ipa-fact--warn' : '' }}">
            <span class="ipa-fact__tile" aria-hidden="true">
                <x-icon :name="$shared ? 'user-group' : 'shield'" width="15" height="15"/>
            </span>
            <span class="ipa-fact__copy">
                <span class="ipa-fact__title">{{ $shared ? __('Shared address') : __('Safe to block') }}</span>
                <span class="ipa-fact__detail">
                    {{ trans_choice('{0} No account has signed in from here in 24h|{1} :count account signed in from here in 24h|[2,*] :count accounts signed in from here in 24h', $accounts, ['count' => $accounts]) }}
                    —
                    @if($shared)
                        {{ __('at or over the shared limit of :limit, so the guard will not block it on its own.', ['limit' => $sharedLimit]) }}
                    @else
                        {{ __('under the shared limit of :limit, so nobody else loses their way in.', ['limit' => $sharedLimit]) }}
                    @endif
                </span>
            </span>
        </div>

        @if($accountList !== [])
            <div class="ipa-section">
                <span class="ipa-label">{{ __('Accounts it reached for') }}</span>
                @foreach($accountList as $email)
                    <div class="ipa-acct">
                        <span class="ipa-acct__name" dir="ltr">{{ $email }}</span>
                        <span class="ipa-acct__state">
                            <x-icon name="shield" width="11" height="11"/>{{ __('Untouched') }}
                        </span>
                    </div>
                @endforeach
                <p class="ipa-hint">{{ __('None was suspended. The sanction is on the address, so everyone else using these sign-ins carries on.') }}</p>
            </div>
        @endif
    </div>

    <div class="ipa-panel__foot">
        <div class="ipa-panel__actions">
            @if($block !== null)
                <form method="POST" action="{{ route('admin.ip-access.destroy', $block->id) }}"
                      onsubmit="return confirm('{{ __('Allow this address to reach the platform again?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ipa-pbtn ipa-pbtn--danger">
                        {{ $lapsed ? __('Remove the record') : __('Unblock now') }}
                    </button>
                </form>
            @endif

            @if($block === null || $automatic)
                <form method="POST" action="{{ route('admin.ip-access.store') }}">
                    @csrf
                    <input type="hidden" name="ip_address" value="{{ $ip }}">
                    <input type="hidden" name="reason" value="{{ $heldBack
                        ? __('Blocked by an administrator after the guard held it back as shared.')
                        : __('Made permanent by an administrator.') }}">
                    <button type="submit" class="ipa-pbtn ipa-pbtn--primary">
                        {{ $heldBack ? __('Block it anyway') : __('Make permanent') }}
                    </button>
                </form>
            @endif
        </div>

        <p class="ipa-hint">
            {{ __('A permanent block is yours: the guard never rewrites it, never shortens it and never lets it lapse.') }}
        </p>
    </div>
</div>
