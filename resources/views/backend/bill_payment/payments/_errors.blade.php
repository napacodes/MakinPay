{{--
    A refused mark-paid or mark-failed comes back here with field errors.
    The dialog reopens with them when its row is still on the page; this
    list says what went wrong either way.
--}}
@if($errors->any())
    <div class="bpa-notice bpa-notice--danger" role="alert">
        <span class="bpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
        <div class="bpa-notice__copy">
            <strong>{{ __('The payment was not updated.') }}</strong>
            <ul class="bpa-notice__list">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
