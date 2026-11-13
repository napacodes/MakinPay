@php
    use App\Http\Requests\Backend\Activity\ActivityLogFilterRequest;
@endphp

<div class="ah-foot">
    <span class="ah-foot__count">
        {!! __('Showing :shown of :total sign-ins', [
            'shown' => '<strong>'.number_format($activities->count()).'</strong>',
            'total' => number_format($activities->total()),
        ]) !!}
    </span>

    <div class="ah-foot__controls">
        <form method="GET" action="{{ route('admin.activity-log') }}" class="ah-rows">
            @foreach(request()->except(['per_page', 'page']) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach

            <label for="ahPerPage">{{ __('Rows') }}</label>
            <select id="ahPerPage" name="per_page" onchange="this.form.submit()">
                @foreach(ActivityLogFilterRequest::PER_PAGE as $size)
                    <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </form>

        <div class="ah-pagination">
            {{ $activities->onEachSide(1)->links() }}
        </div>
    </div>
</div>
