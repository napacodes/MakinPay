{{-- The batch's rows table with its filter, shared by mass payments and mass payouts. Expects $accountLabel and $rowView (the row partial), and from the page $batch, $isDraft, $rows and $currency. --}}
<div class="mp-rows">
    <div class="mp-rows__toolbar">
        <h2 class="mp-rows__title">{{ __('Rows') }}</h2>
        @if($isDraft && ((int) $batch->invalid_count > 0 || (int) $batch->warning_count > 0))
            <div class="mp-filter" role="group" aria-label="{{ __('Filter rows') }}">
                <button type="button" class="mp-filter__btn is-active" data-mp-filter="all" aria-pressed="true">{{ __('All') }}</button>
                @if((int) $batch->invalid_count > 0)
                    <button type="button" class="mp-filter__btn" data-mp-filter="invalid" aria-pressed="false">{{ __('Invalid only') }}</button>
                @endif
                @if((int) $batch->warning_count > 0)
                    <button type="button" class="mp-filter__btn" data-mp-filter="warning" aria-pressed="false">{{ __('Warnings only') }}</button>
                @endif
            </div>
        @endif
    </div>
    <div class="mp-table-wrap">
        <table class="mp-table" data-mp-rows>
            <thead>
                <tr>
                    <th scope="col">{{ __('Line') }}</th>
                    <th scope="col">{{ $accountLabel }}</th>
                    <th scope="col">{{ __('Amount') }}</th>
                    <th scope="col">{{ __('Fee') }}</th>
                    <th scope="col">{{ __('Total') }}</th>
                    <th scope="col">{{ __('Status') }}</th>
                    <th scope="col">{{ __('Details') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @include($rowView, ['row' => $row, 'currency' => $currency])
                @endforeach
            </tbody>
        </table>
    </div>
</div>
