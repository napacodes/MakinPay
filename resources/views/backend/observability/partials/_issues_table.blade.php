{{--
    The Issues table.

    Seven columns, not ten. Level rides with the error it describes, the user
    count sits under the event count it qualifies, and the assignee sits with
    the status it belongs to — three columns that each carried one short value
    and together pushed the right-hand half of the table off the screen.

    Widths are fixed in observability.css so the Error column absorbs the
    remainder. Everything inside it truncates: a culprit like
    "…{closure:Illuminate\Pipeline\Pipeline::prepareDestination():168}" is
    otherwise wide enough on its own to break the layout.

    Every figure in a row is a stored counter on error_groups. The sparkline is
    drawn from the pre-aggregated five-minute buckets. Nothing on this screen
    counts rows in error_events, which is what keeps the list fast on the
    installations that need it most.
--}}
<div class="table-responsive">
    <table class="table mb-0 align-middle obs-table">
        <thead>
        <tr>
            <th scope="col" class="obs-table__select">
                <input type="checkbox"
                       class="form-check-input"
                       data-obs-select-all
                       aria-label="{{ __('Select every error on this page') }}">
            </th>
            <th scope="col">{{ __('Error') }}</th>
            <th scope="col" class="obs-table__source">{{ __('Source') }}</th>
            <th scope="col" class="obs-table__events">{{ __('Events 24h') }}</th>
            <th scope="col" class="obs-table__seen">{{ __('Last seen') }}</th>
            <th scope="col" class="obs-table__status">{{ __('Status') }}</th>
            <th scope="col" class="obs-table__actions admin-table-actions-heading">{{ __('Actions') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($groups as $group)
            <tr>
                <td class="obs-table__select">
                    <input type="checkbox"
                           class="form-check-input"
                           name="groups[]"
                           value="{{ $group->group_id }}"
                           data-obs-select
                           aria-label="{{ __('Select :error', ['error' => $group->title]) }}">
                </td>

                <td>
                    <div class="obs-error">
                        <div class="obs-error__head">
                            <x-observability.severity-badge :level="$group->level"/>
                            <a class="obs-error-class" href="{{ route('admin.observability.issues.show', $group) }}">
                                {{ $group->title }}
                            </a>
                            @if($group->error_code)
                                <a class="obs-code-chip" href="{{ route('admin.observability.codes', ['q' => $group->error_code]) }}">
                                    {{ $group->error_code }}
                                </a>
                            @endif
                        </div>

                        @if($group->message)
                            <span class="obs-error-message" title="{{ $group->message }}">{{ $group->message }}</span>
                        @endif

                        @if($group->culprit)
                            <span class="obs-culprit" title="{{ $group->culprit }}">{{ $group->culprit }}</span>
                        @endif
                    </div>
                </td>

                <td class="obs-table__source">
                    <span class="obs-source">
                        <span class="obs-source__dot" aria-hidden="true"></span>
                        {{ $group->source->chipLabel() }}
                    </span>
                </td>

                <td class="obs-table__events">
                    <span class="obs-metric">{{ number_format($group->event_count) }}</span>
                    <span class="obs-metric__meta">
                        {{ trans_choice('{0}no users|{1}:count user|[2,*]:count users', $group->affected_user_count, ['count' => number_format($group->affected_user_count)]) }}
                    </span>
                    @include('backend.observability.partials._sparkline', [
                        'points' => $sparklines[$group->id] ?? [],
                        'level'  => $group->level,
                    ])
                </td>

                <td class="obs-table__seen">
                    <span class="obs-metric__meta obs-metric__meta--strong">{{ optional($group->last_seen)->diffForHumans() }}</span>
                    <span class="obs-metric__meta">{{ optional($group->last_seen)->format('Y-m-d H:i') }}</span>
                </td>

                <td class="obs-table__status">
                    <span class="obs-status obs-status--{{ $group->status->color() }}">{{ $group->status->label() }}</span>

                    @if($group->assignee)
                        <span class="obs-metric__meta" title="{{ $group->assignee->name }}">
                            {{ __('Assigned to :name', ['name' => $group->assignee->name]) }}
                        </span>
                    @endif
                </td>

                <td class="obs-table__actions admin-table-actions-cell">
                    <div class="admin-table-actions">
                        <a href="{{ route('admin.observability.issues.show', $group) }}"
                           class="btn btn-outline-primary btn-sm admin-table-action"
                           title="{{ __('Open this error') }}">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <span>{{ __('View') }}</span>
                        </a>

                        @can('observability-manage')
                            @if($group->status !== \App\Enums\Observability\ErrorStatus::Resolved)
                                <form action="{{ route('admin.observability.issues.resolve', $group) }}"
                                      method="POST"
                                      class="admin-table-actions__form">
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-outline-success btn-sm admin-table-action admin-table-action--success"
                                            title="{{ __('Mark this error fixed') }}">
                                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                                        <span>{{ __('Resolve') }}</span>
                                    </button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
