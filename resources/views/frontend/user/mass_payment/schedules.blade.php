@extends('frontend.layouts.user.index')
@section('title', __('Scheduled batches'))

@section('content')
    <div class="mp" data-mp-schedules-page>
        <div class="single-form-card">
            <x-user-feature-header
                :title="__('Scheduled batches')"
                :subtitle="__('Batches paid again on a cadence - payroll, rent, the weekly float. Each run is a fresh batch: every recipient checked again, nothing taken until it runs.')"
                icon="fas fa-rotate"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payment.index') }}">
                    <i class="fas fa-list" aria-hidden="true"></i> {{ __('History') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                @if($schedules->isEmpty())
                    <x-user-not-found :title="__('No scheduled batches yet')" :message="__('Open a batch you have paid and choose Pay this batch again on a schedule.')" icon="fa-rotate" />
                @else
                    <div class="table-responsive">
                        <table class="table mp-table mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Batch') }}</th>
                                    <th scope="col">{{ __('How often') }}</th>
                                    <th scope="col" class="text-end">{{ __('Lines') }}</th>
                                    <th scope="col" class="text-end">{{ __('Amount') }}</th>
                                    <th scope="col">{{ __('Next run') }}</th>
                                    <th scope="col">{{ __('Last run') }}</th>
                                    <th scope="col">{{ __('Status') }}</th>
                                    <th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($schedules as $schedule)
                                    @php
                                        $mpRows    = count((array) (($schedule->payload ?? [])['rows'] ?? []));
                                        $mpCadence = match ($schedule->cadence) {
                                            'weekly'  => __('Every week'),
                                            'monthly' => __('Every month, day :day', ['day' => $schedule->day_of_month]),
                                            'once'    => __('Once'),
                                            default   => __('Every :days days', ['days' => $schedule->interval_days]),
                                        };
                                        $mpLast  = $schedule->runs->first();
                                        $mpBatch = $mpLast?->reference !== null ? ($batches[$mpLast->reference] ?? null) : null;
                                    @endphp
                                    <tr data-mp-schedule="{{ $schedule->id }}" data-mp-schedule-status="{{ $schedule->status }}">
                                        <td>
                                            <span class="fw-semibold">{{ $schedule->recipient_name ?: __('Mass payment') }}</span>
                                            <br><small class="text-muted">{{ __('From your :currency wallet', ['currency' => $schedule->currency]) }}</small>
                                        </td>
                                        <td>{{ $mpCadence }}@if($schedule->ends_on)<br><small class="text-muted">{{ __('until :date', ['date' => $schedule->ends_on->translatedFormat('j M Y')]) }}</small>@endif</td>
                                        <td class="text-end">{{ $mpRows }}</td>
                                        <td class="text-end" dir="ltr">{{ money($schedule->amount, $schedule->currency, false) }} {{ $schedule->currency }}</td>
                                        <td dir="ltr">{{ $schedule->next_run_at?->translatedFormat('D j M Y') ?? '—' }}</td>
                                        <td>
                                            @if($mpLast === null)
                                                <span class="text-muted">—</span>
                                            @else
                                                <span class="{{ $mpLast->status === 'success' ? 'text-success' : 'text-danger' }}">{{ ucfirst($mpLast->status) }}</span>
                                                <small class="text-muted" dir="ltr">· {{ $mpLast->due_at?->translatedFormat('j M') }}</small>
                                                @if($mpBatch !== null)
                                                    <br><a href="{{ route('user.mass-payment.show', $mpBatch) }}" class="small" data-mp-schedule-batch>{{ $mpBatch->reference }}</a>
                                                @elseif($mpLast->reason)
                                                    <br><small class="text-muted">{{ $mpLast->reason }}</small>
                                                @endif
                                            @endif
                                        </td>
                                        <td>
                                            <span class="mp-pill mp-pill--{{ match ($schedule->status) { 'active' => 'success', 'paused' => 'warning', 'completed' => 'dark', default => 'danger' } }}">{{ match ($schedule->status) { 'active' => __('Running'), 'paused' => __('Paused'), 'completed' => __('Done'), 'cancelled' => __('Cancelled'), default => ucfirst($schedule->status) } }}</span>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            @if($schedule->status === 'active')
                                                <form method="POST" action="{{ route('user.mass-payment.schedules.pause', $schedule) }}" class="d-inline">@csrf<button type="submit" class="btn btn-light btn-sm" data-mp-schedule-pause>{{ __('Pause') }}</button></form>
                                            @elseif($schedule->status === 'paused')
                                                <form method="POST" action="{{ route('user.mass-payment.schedules.resume', $schedule) }}" class="d-inline">@csrf<button type="submit" class="btn btn-light btn-sm" data-mp-schedule-resume>{{ __('Resume') }}</button></form>
                                            @endif
                                            @if(in_array($schedule->status, ['active', 'paused'], true))
                                                <form method="POST" action="{{ route('user.mass-payment.schedules.cancel', $schedule) }}" class="d-inline" data-confirm-message="{{ __('Cancel this schedule? Batches already paid are unaffected.') }}">@csrf<button type="submit" class="btn btn-light-danger btn-sm" data-mp-schedule-cancel>{{ __('Cancel') }}</button></form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
