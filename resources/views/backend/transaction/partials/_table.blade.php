{{--
    The transactions table.

    One table, rendered the same wherever transactions are listed. It began as
    markup inside the ledger page while the dashboard grew a second, similar
    table of its own — two designs for one thing, two sets of styles and two
    scripts to keep in step. This is the ledger's, moved out unchanged.

    Props:
      $transactions  a Collection or Paginator of Transaction models
      $selectable    render the checkbox column. The ledger selects rows; a
                     "latest ten" panel has nothing to do with a selection.
      $grouped       group the mobile list by day. Off where the list is short
                     enough that a date header per row is noise.

    Every row carries `data-trx-url`, so wherever this table appears a click
    opens the same detail modal — provided the page also includes
    `partials/_detail_modal` and `backend/js/transaction-detail.js`.

    `.admin-trx-list` is not decoration: it declares the `--aul-*` tokens the
    row classes resolve. Without it the pills render transparent.
--}}
@php
    $selectable = $selectable ?? false;
    $grouped    = $grouped ?? true;
    $compact    = $compact ?? false;
@endphp
@php
    use App\Enums\TrxType;

    /*
     * Tone and glyph per transaction type. Money coming toward the wallet
     * reads green, money leaving reads red, and the rest are grouped so a
     * row can be recognised before its label is read.
     */
    $trxTone = static function (TrxType $type): string {
        return match ($type) {
            TrxType::DEPOSIT, TrxType::RECEIVE_MONEY, TrxType::RECEIVE_PAYMENT, TrxType::REFUND,
            TrxType::REFERRAL_REWARD, TrxType::REWARD, TrxType::SIGNUP_BONUS, TrxType::AGENT_CASH_IN,
            TrxType::AGENT_COMMISSION, TrxType::REMITTANCE_RECEIVE, TrxType::REMITTANCE_REFUND,
            TrxType::WALLET_EARN_REWARD, TrxType::WALLET_EARN_PRINCIPAL, TrxType::P2P_RELEASE,
            TrxType::P2P_REFUND, TrxType::ADD_BALANCE, TrxType::MARKETPLACE_PAYOUT,
            TrxType::MARKETPLACE_REFUND, TrxType::GIFT_CARD_REDEEM, TrxType::CASHBACK,
            TrxType::VAULT_WITHDRAW => 'success',

            TrxType::WITHDRAW, TrxType::CARD_WITHDRAW, TrxType::AGENT_CASH_OUT,
            TrxType::SUBTRACT_BALANCE => 'danger',

            TrxType::SEND_MONEY, TrxType::MASS_PAYMENT, TrxType::REQUEST_MONEY, TrxType::CARD_TOPUP,
            TrxType::P2P_ESCROW, TrxType::WALLET_EARN_STAKE, TrxType::VAULT_DEPOSIT => 'primary',

            TrxType::PAYMENT, TrxType::SUBSCRIPTION, TrxType::SUBSCRIPTION_RENEWAL,
            TrxType::MARKETPLACE_SALE, TrxType::MARKETPLACE_COMMISSION,
            TrxType::P2P_PROMOTION => 'warning',

            TrxType::EXCHANGE_MONEY, TrxType::REMITTANCE_SEND => 'info',

            TrxType::MOBILE_RECHARGE, TrxType::BILL_PAYMENT, TrxType::VOUCHER, TrxType::GIFT_CARD => 'violet',

            default => 'neutral',
        };
    };

    $trxGlyph = static function (TrxType $type): string {
        return match ($type) {
            TrxType::DEPOSIT, TrxType::ADD_BALANCE, TrxType::AGENT_CASH_IN
                => 'M12 3v11M7.5 9.5L12 14l4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2',
            TrxType::WITHDRAW, TrxType::CARD_WITHDRAW, TrxType::AGENT_CASH_OUT, TrxType::SUBTRACT_BALANCE
                => 'M12 14V3M7.5 7.5L12 3l4.5 4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2',
            TrxType::SEND_MONEY, TrxType::MASS_PAYMENT, TrxType::REMITTANCE_SEND
                => 'M4 12h15M13 6l6 6-6 6',
            TrxType::RECEIVE_MONEY, TrxType::RECEIVE_PAYMENT, TrxType::REMITTANCE_RECEIVE
                => 'M20 12H5M11 6l-6 6 6 6',
            TrxType::PAYMENT, TrxType::MARKETPLACE_SALE
                => 'M6 7h12l1 13H5zM9 7V5a3 3 0 0 1 6 0v2',
            TrxType::EXCHANGE_MONEY
                => 'M4 8h13M14 5l3 3-3 3M20 16H7M10 13l-3 3 3 3',
            TrxType::MOBILE_RECHARGE
                => 'M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM12 18h.01',
            TrxType::BILL_PAYMENT
                => 'M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1zM14 8H8M16 12H8M13 16H8',
            TrxType::REFUND, TrxType::MARKETPLACE_REFUND, TrxType::REMITTANCE_REFUND, TrxType::P2P_REFUND
                => 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5',
            TrxType::SUBSCRIPTION, TrxType::SUBSCRIPTION_RENEWAL
                => 'M17 2l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 22l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3',
            TrxType::CARD_TOPUP
                => 'M2 7h20v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM2 11h20',
            TrxType::VAULT_DEPOSIT, TrxType::VAULT_WITHDRAW
                => 'M4 7h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1zM12 11v5M9.5 13.5h5M7 20v2M17 20v2',
            default
                => 'M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
        };
    };
@endphp

<div class="admin-trx-list">
    <div class="admin-users-table-wrap">
        @if($transactions->isNotEmpty())
            {{-- `--wide` pins a 76rem minimum so the ledger's seven columns
                 keep their designed widths and scroll inside the card. A
                 dashboard column is narrower than that, so a compact table
                 would scroll sideways however much is stripped out of a row;
                 it gets a floor that fits the space it is given instead. --}}
            <table class="table admin-users-table {{ $compact ? 'admin-trx-table--compact' : 'admin-users-table--wide' }} admin-trx-table mb-0">
                <thead>
                <tr>
                    @if($selectable)
                        <th class="admin-trx-check">
                            <input type="checkbox" class="admin-check" data-trx-select-all aria-label="{{ __('Select all') }}">
                        </th>
                    @endif
                    <th>{{ __('Transaction') }}</th>
                    <th>{{ __('Customer') }}</th>
                    <th class="text-end">{{ __('Amount') }}</th>
                    <th>{{ __('Provider') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="text-end">{{ __('Time') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($transactions as $transaction)
                    @php
                        $avatarData  = getUserAvatarDetails($transaction->user?->first_name, $transaction->user?->last_name);
                        $amountColor = $transaction->amount_flow->color($transaction->status);
                        $amountSign  = $transaction->amount_flow->sign($transaction->status);
                        $tone        = $trxTone($transaction->trx_type);
                        /* A transaction outlives the account that made it, and this table is
                           now shown on a screen where that shows up. */
                        $customerName = $transaction->user?->name ?: __('Deleted user');
                        $customerUrl  = $transaction->user
                            ? route('admin.user.manage', $transaction->user->username)
                            : null;
                    @endphp
                    {{--
                        Without the chevron there is nothing in the row a
                        keyboard can reach, and a `tr` is not focusable by
                        default — so the compact table puts the focus on the
                        row itself and names it. One tab stop per row either
                        way; the difference is that this one costs no width.
                    --}}
                    <tr class="admin-users-row admin-trx-row align-middle"
                        data-trx-row
                        data-trx-url="{{ route('admin.transaction.show', $transaction->trx_id) }}"
                        data-status="{{ $transaction->status->value }}"
                        @if($compact)
                            tabindex="0"
                            aria-label="{{ __('View transaction :id', ['id' => strtoupper($transaction->trx_id)]) }}"
                        @endif>
                        @if($selectable)
                            <td class="admin-trx-check">
                                <input type="checkbox" class="admin-check" data-trx-select aria-label="{{ __('Select transaction') }}">
                            </td>
                        @endif
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <span class="admin-trx-tile admin-trx-tile--{{ $tone }}" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $trxGlyph($transaction->trx_type) }}"></path></svg>
                                </span>
                                <div class="admin-trx-type__copy">
                                    <span class="admin-users-name">{{ $transaction->trx_type->label() }}</span>
                                    <span class="admin-users-meta d-flex align-items-center gap-2">
                                        <code class="admin-trx-id">{{ strtoupper($transaction->trx_id) }}</code>
                                        @unless($compact)
                                            <span class="admin-trx-desc">{{ $transaction->description }}</span>
                                        @endunless
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar avatar-md">
                                    @isset($transaction->user?->avatar)
                                        <img class="avatar-img" src="{{ asset($transaction->user?->avatar) }}" height="40" alt="{{ __('User avatar') }}" loading="lazy">
                                    @else
                                        <div class="avatar avatar-md {{ $avatarData['class'] }} text-white">
                                            {{ $avatarData['initials'] }}
                                        </div>
                                    @endisset
                                </div>
                                <div class="admin-users-person__copy">
                                    <a @if($customerUrl) href="{{ $customerUrl }}" @endif class="admin-users-name text-decoration-none">
                                        {{ $customerName }}
                                    </a>
                                    <span class="admin-users-meta">{{ $transaction->user ? '@'.$transaction->user->username : '' }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="text-end">
                            {{-- money() formats at the currency's own precision. Echoing the
                                 column printed its decimal(24,8) padding, so a $1,000 deposit
                                 read as "1000.00000000" and a BTC one lost nothing but gained
                                 six zeros nobody needs. --}}
                            <span class="admin-users-name admin-trx-amount {{ $amountColor }}">
                                {{ $amountSign }}{{ money($transaction->amount, $transaction->currency) }}
                            </span>
                            <span class="admin-users-meta">
                                {{ __('Fee :fee', ['fee' => money($transaction->fee, $transaction->currency)]) }}
                            </span>
                        </td>
                        <td>
                            <span class="admin-users-name">{{ $transaction->provider }}</span>
                            <span class="admin-users-meta">{{ $transaction->processing_type->label() }}</span>
                        </td>
                        <td>
                            <span class="admin-users-pill admin-users-pill--{{ $transaction->status->color() }}">
                                {{ $transaction->status->label() }}
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <div>
                                    <span class="admin-users-name">{{ $transaction->created_at->format('H:i') }}</span>
                                    <span class="admin-users-meta">{{ $transaction->created_at->diffForHumans() }}</span>
                                </div>
                                @unless($compact)
                                    {{--
                                        The row opens the modal on
                                        click, but a `tr` cannot take
                                        focus on its own, so the
                                        chevron is the keyboard way
                                        in — the same modal the
                                        mobile list opens from a
                                        button. Enter and Space fire
                                        a click, which the row's own
                                        handler picks up; no extra
                                        binding.

                                        A compact table drops it for
                                        the width and makes the row
                                        itself focusable instead;
                                        see the `tr` above.
                                    --}}
                                    <button type="button" class="admin-trx-open" data-trx-open
                                            aria-label="{{ __('View transaction :id', ['id' => strtoupper($transaction->trx_id)]) }}">
                                        <svg class="admin-trx-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"></polyline></svg>
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            {{-- Under 576px the seven columns become an app-style list, grouped
                 by the day each transaction was recorded. --}}
            <div class="admin-trx-mobile">
                {{-- One bucket per day, or one bucket for everything: a panel
                     showing the latest ten would otherwise be mostly date
                     headers, and filtering it would strand the header of a day
                     whose rows had all been hidden. --}}
                @foreach($grouped ? $transactions->groupBy(fn ($transaction) => $transaction->created_at->toDateString()) : collect([$transactions]) as $day => $dayTransactions)
                    @if($grouped)
                        @php $dayDate = \Carbon\Carbon::parse($day); @endphp
                        <div class="admin-trx-group__head">
                            <span>
                                @if($dayDate->isToday()) {{ __('Today') }}
                                @elseif($dayDate->isYesterday()) {{ __('Yesterday') }}
                                @else {{ $dayDate->format('d M') }}
                                @endif
                            </span>
                            {{-- A day with one movement on it read "1
                                 transactions". --}}
                            <span>{{ trans_choice('{1}:count transaction|[2,*]:count transactions', $dayTransactions->count(), ['count' => number_format($dayTransactions->count())]) }}</span>
                        </div>
                    @endif
                    <div class="admin-trx-group">
                        @foreach($dayTransactions as $transaction)
                            @php
                                $avatarData  = getUserAvatarDetails($transaction->user?->first_name, $transaction->user?->last_name);
                                $amountColor = $transaction->amount_flow->color($transaction->status);
                                $amountSign  = $transaction->amount_flow->sign($transaction->status);
                                $tone        = $trxTone($transaction->trx_type);

                                /*
                                 * A provider is optional. `provider` is a
                                 * nullable column and an exchange between a
                                 * user's own two wallets never has one, so a
                                 * literal separator printed "Exchange Money
                                 * ·" — a dot pointing at nothing — on every
                                 * wallet-to-wallet movement in the ledger.
                                 * The desktop table never showed it because
                                 * there the provider is its own column, and
                                 * an empty cell reads as empty.
                                 */
                                $meta = implode(' · ', array_filter([
                                    $transaction->trx_type->label(),
                                    $transaction->provider,
                                ], 'filled'));
                            @endphp
                            <button type="button" class="admin-trx-mrow"
                                    data-trx-row
                                    data-trx-url="{{ route('admin.transaction.show', $transaction->trx_id) }}"
                        data-status="{{ $transaction->status->value }}">
                                <span class="admin-trx-tile admin-trx-tile--{{ $tone }} admin-trx-tile--lg" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $trxGlyph($transaction->trx_type) }}"></path></svg>
                                </span>
                                <span class="admin-trx-mrow__body">
                                    <span class="admin-trx-mrow__title">{{ $transaction->user?->name ?: __('Deleted user') }}</span>
                                    <span class="admin-trx-mrow__meta">{{ $meta }}</span>
                                </span>
                                <span class="admin-trx-mrow__side">
                                    <span class="admin-trx-mrow__amount {{ $amountColor }}">{{ $amountSign }}{{ money($transaction->amount, $transaction->currency) }}</span>
                                    <span class="admin-trx-mrow__status admin-trx-mrow__status--{{ $transaction->status->color() }}">{{ $transaction->status->label() }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @else
            <x-admin-not-found
                :title="__('No transactions found')"
                :message="__('Transactions matching the current filters will appear here.')"
                icon="fa-receipt"
            />
        @endif
    </div>
</div>
