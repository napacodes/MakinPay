@extends('backend.background_tasks.layout')

@section('title', __('Failed jobs'))

@section('bt_content')
    @if($count > 0)
        <div class="bt-toolbar">
            <span class="bt-toolbar__summary">
                <strong class="is-danger">{{ __(':n failed jobs', ['n' => number_format($count)]) }}</strong>
                · @lang('retry once the cause is fixed, delete the ones that must not run again')
            </span>

            <div class="bt-toolbar__tools">
                <form method="POST" action="{{ route('admin.queue.retry-all') }}">
                    @csrf
                    <button type="submit" class="bt-btn-retry">
                        <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>@lang('Retry all')
                    </button>
                </form>

                {{-- Both destructive paths open the same dialog; only the copy
                     and the form it posts to differ. --}}
                <button type="button"
                        class="bt-btn-danger"
                        data-coreui-toggle="modal"
                        data-coreui-target="#bt-confirm-modal"
                        data-bt-confirm
                        data-action="{{ route('admin.queue.flush') }}"
                        data-method="POST"
                        data-title="{{ __('Flush all :n failed jobs?', ['n' => number_format($count)]) }}"
                        data-body="{{ __('This permanently deletes every failed job. It cannot be undone.') }}"
                        data-cta="{{ __('Flush all') }}">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>@lang('Flush all')
                </button>
            </div>
        </div>

        <div class="bt-tablewrap">
            <div class="bt-grid bt-grid--failed">
                <div class="bt-grid__head">
                    <span></span>
                    <span>@lang('Job')</span>
                    <span>@lang('Queue')</span>
                    <span>@lang('Exception')</span>
                    <span>@lang('Failed')</span>
                    <span></span>
                </div>

                @foreach($failedJobs as $btJob)
                    @php
                        $btPayload  = json_decode($btJob->payload ?? '{}', true);
                        /*
                         * The identifier queue:retry and queue:forget look up.
                         * The default failer (database-uuids) finds a job by
                         * its uuid and knows nothing of the row id, so a
                         * numeric id posted here matched nothing — and the
                         * screen still announced the retry.
                         */
                        $btJobId    = (string) ($btJob->uuid ?? $btJob->id);
                        $btClass    = (string) ($btPayload['displayName'] ?? __('Unknown job'));
                        $btName     = class_basename($btClass);
                        $btFailedAt = \Illuminate\Support\Carbon::parse($btJob->failed_at);
                        $btFirst    = strtok(trim((string) $btJob->exception), "\n");
                    @endphp
                    <div class="bt-erow" data-bt-expand aria-expanded="false">
                        <div class="bt-erow__row">
                            <span class="bt-chevbox" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>

                            <span class="bt-job">
                                <span class="bt-job__name">{{ $btName }}</span>
                                <span class="bt-job__class" dir="ltr" title="{{ $btClass }}">{{ $btClass }}</span>
                            </span>

                            <span class="bt-queuechip" dir="ltr">{{ $btJob->queue }}</span>

                            <span class="bt-exc">{{ $btFirst === false ? '' : $btFirst }}</span>

                            <span class="bt-when">
                                <span class="bt-when__time">{{ $btFailedAt->diffForHumans() }}</span>
                                <span class="bt-when__date" dir="ltr">{{ $btFailedAt->format('d M H:i') }}</span>
                            </span>

                            <span class="bt-erow__actions">
                                <form method="POST" action="{{ route('admin.queue.retry', $btJobId) }}">
                                    @csrf
                                    <button type="submit" class="bt-btn-retry">
                                        <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>@lang('Retry')
                                    </button>
                                </form>

                                <button type="button"
                                        class="bt-iconbtn-danger"
                                        data-coreui-toggle="modal"
                                        data-coreui-target="#bt-confirm-modal"
                                        data-bt-confirm
                                        data-bt-delete-job
                                        aria-label="{{ __('Delete job') }}"
                                        data-action="{{ route('admin.queue.forget', $btJobId) }}"
                                        data-method="DELETE"
                                        data-job="{{ $btName }}"
                                        data-queue="{{ $btJob->queue }}"
                                        data-title="{{ __('Delete this failed job?') }}"
                                        data-body="{{ __(':job on the :queue queue will be removed permanently. Retry it instead if the cause is fixed.', ['job' => $btName, 'queue' => $btJob->queue]) }}"
                                        data-cta="{{ __('Delete job') }}">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                </button>
                            </span>
                        </div>

                        <div class="bt-erow__panel">
                            <pre class="bt-pre is-failed">{{ $btJob->exception }}</pre>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @if($failedJobs->hasPages())
            <div class="bt-tablefoot">
                {{ $failedJobs->links() }}
            </div>
        @endif
    @else
        <div class="bt-empty bt-empty--healthy">
            <span class="bt-empty__tile is-ok" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
            <span class="bt-empty__title">@lang('Queue is healthy')</span>
            <span class="bt-empty__body">@lang('No failed jobs. Everything the worker picked up finished.')</span>
        </div>
    @endif

@endsection
