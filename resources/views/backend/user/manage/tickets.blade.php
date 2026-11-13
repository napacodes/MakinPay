@extends('backend.user.manage')

@section('user_manage_content')
    <section class="um-card" data-um-tickets>
        <div class="um-card__head">
            <h2 class="um-card__title">{{ __('Support tickets') }}</h2>
            <span class="um-card__aside">{{ __('Newest first') }}</span>
        </div>

        @forelse($tickets as $ticket)
            @php
                $tone = match ($ticket->status) {
                    \App\Enums\TicketStatus::CLOSED  => 'neutral',
                    \App\Enums\TicketStatus::REPLIED => 'brand',
                    default                          => 'warning',
                };
            @endphp
            <a href="{{ route('admin.support-ticket.show', $ticket->id) }}" class="um-list__row">
                <span class="um-list__copy">
                    <span class="um-list__title"><span class="um-mono" dir="ltr">#{{ strtoupper($ticket->uuid) }}</span> · {{ $ticket->title }}</span>
                    <span class="um-list__sub">{{ $ticket->category?->name ?? __('General') }} · {{ $ticket->priority->label() }}@if($ticket->is_resolved) · {{ __('Resolved') }}@endif</span>
                </span>
                <span class="um-list__end">
                    <span class="um-badge um-badge--{{ $tone }}">{{ $ticket->status->label() }}</span>
                    <span class="um-list__when">{{ $ticket->created_at->diffForHumans() }}</span>
                </span>
            </a>
        @empty
            <div class="um-card__empty">{{ __('This user has not opened any support tickets yet.') }}</div>
        @endforelse

        @if($tickets->hasPages())
            <div class="um-pager">
                <span>{{ __('Showing :from–:to of :total', ['from' => $tickets->firstItem(), 'to' => $tickets->lastItem(), 'total' => $tickets->total()]) }}</span>
                <div class="um-pager__nav">
                    <a class="um-btn um-btn--sm" href="{{ $tickets->previousPageUrl() ?? '#' }}" @if($tickets->onFirstPage()) aria-disabled="true" @endif>{{ __('Previous') }}</a>
                    <a class="um-btn um-btn--sm" href="{{ $tickets->nextPageUrl() ?? '#' }}" @unless($tickets->hasMorePages()) aria-disabled="true" @endunless>{{ __('Next') }}</a>
                </div>
            </div>
        @endif
    </section>
@endsection
