{{-- A confirmed batch's progress bar and legend, shared by mass payments and mass payouts. Expects $statusUrl, and from the page $counts, $mpPercent, $mpIsLive and $pollInterval. --}}
<div class="mp-progress" data-mp-progress
     @if($mpIsLive)
         data-status-url="{{ $statusUrl }}"
         data-poll-interval="{{ $pollInterval }}"
         data-initial-status="{{ $batch->status()->value }}"
     @endif>
    <div class="mp-progress__bar" role="img" aria-label="{{ __(':paid paid, :failed refunded, :pending remaining', ['paid' => $counts['paid'], 'failed' => $counts['failed'], 'pending' => $counts['pending']]) }}">
        <span class="mp-progress__seg mp-progress__seg--paid" data-mp-seg="paid" style="inline-size: {{ $mpPercent($counts['paid']) }}%"></span>
        <span class="mp-progress__seg mp-progress__seg--failed" data-mp-seg="failed" style="inline-size: {{ $mpPercent($counts['failed'] + $counts['reviewed']) }}%"></span>
    </div>
    <dl class="mp-progress__legend">
        <div><dt><span class="mp-swatch mp-swatch--paid" aria-hidden="true"></span>{{ __('Paid') }}</dt><dd data-mp-count="paid">{{ $counts['paid'] }}</dd></div>
        <div><dt><span class="mp-swatch mp-swatch--failed" aria-hidden="true"></span>{{ __('Refunded') }}</dt><dd data-mp-count="failed">{{ $counts['failed'] }}</dd></div>
        <div><dt><span class="mp-swatch" aria-hidden="true"></span>{{ __('Remaining') }}</dt><dd data-mp-count="pending">{{ $counts['pending'] }}</dd></div>
        @if($counts['skipped'] > 0)
            <div><dt>{{ __('Skipped') }}</dt><dd data-mp-count="skipped">{{ $counts['skipped'] }}</dd></div>
        @endif
    </dl>
</div>
