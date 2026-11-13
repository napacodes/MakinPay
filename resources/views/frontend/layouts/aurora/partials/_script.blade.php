{{-- jQuery (used by other site-wide scripts; the Aurora bundle is vanilla JS) --}}
<script src="{{ asset('general/js/jquery.min.js') }}"></script>

{{-- Notifications --}}
<script src="{{ asset('general/js/simple-notify.min.js') }}"></script>

@if(session()->has('notifyevs'))
	@php $notify = session('notifyevs'); @endphp
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			if (typeof Notify === 'function') {
				new Notify({
					status: @json($notify['type']),
					title: '',
					text: @json($notify['message']),
					effect: 'fade',
					speed: 300,
					autoclose: true,
					autotimeout: 3500,
					position: 'right top',
				});
			}
		});
	</script>
@endif

{{-- Aurora bundle: sticky header, drawer, mode toggle, portal tabs, catalog filter,
     card preview, remittance calculator, scroll reveal --}}
<script src="{{ asset('assets/frontend/js/aurora/theme.js?v='.config('app.version')) }}"></script>

@stack('scripts')
@yield('scripts')
