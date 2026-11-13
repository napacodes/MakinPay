@php
	/*
	 * The trail the menu config already knows about.
	 *
	 * `$adminBreadcrumb` is composed into the sidebar and the header only, so a
	 * page's own breadcrumb section cannot see it — this asks AdminShell
	 * directly rather than every page repeating its own labels and drifting
	 * from the menu.
	 */
	$crumbs = $crumbs ?? \App\Support\AdminShell::breadcrumb();
@endphp

@foreach($crumbs as $crumb)
	@if(! $loop->first)
		<span class="dk-crumb-sep" aria-hidden="true">›</span>
	@endif

	@if($loop->last)
		<span class="dk-crumb-current" aria-current="page">{{ $crumb['label'] }}</span>
	@elseif(! empty($crumb['url']))
		<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
	@else
		<span>{{ $crumb['label'] }}</span>
	@endif
@endforeach
