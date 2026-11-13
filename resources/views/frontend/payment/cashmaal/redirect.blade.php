<!DOCTYPE html>
{{--
    The documented CashMaal checkout is a POST, so the customer arrives here
    and is forwarded. Every field below is a documented request parameter; no
    credential is among them.

    Deliberately standalone rather than extending the site layout: this page is
    a forwarding step, it renders for a few hundred milliseconds, and pulling in
    the full chrome would make a payment redirect depend on unrelated view data.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('Redirecting to CashMaal') }}</title>
</head>
<body>
    <p>{{ __('Redirecting you to CashMaal. Please wait and do not close this window.') }}</p>

    <form id="cashmaal-checkout" method="post" action="{{ $endpoint }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        {{-- Works without JavaScript, and if the auto-submit is blocked. --}}
        <button type="submit">{{ __('Continue to CashMaal') }}</button>
    </form>

    <script>
        "use strict";
        document.getElementById('cashmaal-checkout').submit();
    </script>
</body>
</html>
