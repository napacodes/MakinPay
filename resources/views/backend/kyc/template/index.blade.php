@php
    use App\Enums\UserRole;
@endphp
@extends('backend.layouts.app')
@section('title', __('KYC Templates'))

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('backend/css/kyc-template.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/kyc-template.css'))) }}">
@endpush

@section('content')
    @php
        $totalTemplates    = $templates->count();
        $activeTemplates   = $templates->where('status', true)->count();
        $allFields         = $templates->flatMap(fn ($t) => is_array($t->fields) ? $t->fields : []);
        $uploadFields      = $allFields->where('type', 'file')->count();
        $typedFields       = $allFields->count() - $uploadFields;
        $coveredRoles      = $templates
            ->flatMap(fn ($t) => is_array($t->applicable_to) ? $t->applicable_to : [])
            ->unique()
            ->values();
        $rolesCovered      = $coveredRoles->count();
        $everyRole         = count(UserRole::cases());
        $totalSubmissions  = $templates->sum('submissions_count');

        /*
         * The chip says what the field is, in words, rather than echoing the
         * stored value: "no." is what the column has room for and "upload" is
         * what an admin calls a file field.
         */
        $typeLabels = [
            'text'    => __('text'),
            'file'    => __('upload'),
            'number'  => __('no.'),
            'date'    => __('date'),
            'country' => __('country'),
        ];
    @endphp

    <div class="admin-users-page admin-kyc-page">
        <x-page-header
            :title="__('KYC Templates')"
            :subtitle="__('The forms applicants fill in. A template is what a reviewer later reads, so its field names are the words on the review screen.')"
            icon="fa-file-lines"
            tile="violet"
            :status="$rolesCovered >= $everyRole
                ? ['tone' => 'success', 'label' => __('Every role covered')]
                : ['tone' => 'warning', 'label' => __(':count of :total roles covered', ['count' => $rolesCovered, 'total' => $everyRole])]"
            :stats="[
                [
                    'label' => __('Templates'),
                    'value' => number_format($totalTemplates),
                    'note'  => __(':active active, :paused paused', [
                        'active' => $activeTemplates,
                        'paused' => $totalTemplates - $activeTemplates,
                    ]),
                ],
                [
                    'label' => __('Fields defined'),
                    'value' => number_format($allFields->count()),
                    'note'  => __(':uploads uploads, :typed typed', [
                        'uploads' => $uploadFields,
                        'typed'   => $typedFields,
                    ]),
                ],
                [
                    'label' => __('Roles covered'),
                    'value' => $rolesCovered . ' ' . __('of') . ' ' . $everyRole,
                    'note'  => $coveredRoles
                        ->map(fn ($slug) => UserRole::tryFrom($slug)?->title())
                        ->filter()
                        ->implode(', '),
                ],
                [
                    'label' => __('Submissions received'),
                    'value' => number_format($totalSubmissions),
                    'note'  => __('across all templates'),
                ],
            ]"
            class="mb-3"
        >
            <x-slot:actions>
                @can('kyc-template-manage')
                    <button type="button"
                            class="btn btn-primary"
                            data-coreui-toggle="modal"
                            data-coreui-target="#createTemplateModal">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        {{ __('New template') }}
                    </button>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <section class="admin-users-panel">
            <div class="admin-users-table-wrap">
                @if($totalTemplates > 0)
                    <table class="table admin-users-table mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Template') }}</th>
                                <th>{{ __('Fields') }}</th>
                                <th>{{ __('Applies to') }}</th>
                                <th>{{ __('Submissions') }}</th>
                                <th>{{ __('Last edited') }}</th>
                                @can('kyc-template-manage')
                                    <th>{{ __('Live') }}</th>
                                    <th class="text-end">{{ __('Actions') }}</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($templates as $template)
                                @php
                                    $fields    = is_array($template->fields) ? collect($template->fields) : collect();
                                    $required  = $fields->filter(fn ($f) => ($f['required'] ?? 'false') === 'true')->count();
                                    $byType    = $fields->countBy(fn ($f) => $f['type'] ?? 'text');
                                    $total     = (int) $template->submissions_count;
                                    $approved  = (int) $template->approved_count;
                                    $rejected  = (int) $template->rejected_count;
                                    $pending   = (int) $template->pending_count;

                                    /* The same shape the builder's live preview renders from. */
                                    $previewFields = $fields
                                        ->map(fn ($f) => [
                                            'label'    => (string) ($f['label'] ?? ''),
                                            'type'     => $f['type'] ?? 'text',
                                            'required' => ($f['required'] ?? 'false') === 'true' || ($f['required'] ?? false) === true,
                                        ])
                                        ->filter(fn ($f) => $f['label'] !== '')
                                        ->values();
                                @endphp
                                <tr class="admin-users-row align-middle {{ $template->status ? '' : 'kt-row--paused' }}">
                                    {{-- Template --}}
                                    <td>
                                        <div class="admin-users-doc">
                                            {{--
                                                The chip is a sibling of the name, not a child:
                                                .admin-users-name clips its overflow, so a nested
                                                chip would be cut off on a long title.
                                            --}}
                                            <span class="kt-titleline">
                                                <span class="admin-users-name">{{ $template->title }}</span>
                                                @unless($template->status)
                                                    {{-- Never used and switched off is a draft, not a pause. --}}
                                                    <span class="kt-type">{{ $total > 0 ? __('Paused') : __('Draft') }}</span>
                                                @endunless
                                                @if($template->isManualOnly())
                                                    <span class="kt-type kt-type--manual">{{ __('Manual only') }}</span>
                                                @endif
                                            </span>
                                            @if(filled($template->description))
                                                <span class="admin-users-doc__hint">{{ $template->description }}</span>
                                            @elseif(! $template->status && $total > 0)
                                                <span class="admin-users-doc__hint kt-hint--warn">{{ __('Applicants cannot see this, and :count submissions are attached to it.', ['count' => $total]) }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    {{-- Fields: what the form is made of, not just how many. --}}
                                    <td>
                                        <span class="kt-fieldcount">
                                            <span class="kt-fieldcount__n">{{ $fields->count() }}</span>
                                            <span class="kt-fieldcount__label">{{ trans_choice('field · :required required|fields · :required required', $fields->count(), ['required' => $required]) }}</span>
                                        </span>
                                        @if($byType->isNotEmpty())
                                            <span class="kt-types">
                                                @foreach($byType as $type => $count)
                                                    <span class="kt-type kt-type--{{ $type }}">{{ $count }} {{ $typeLabels[$type] ?? $type }}</span>
                                                @endforeach
                                            </span>
                                        @endif
                                    </td>

                                    {{-- Applies to --}}
                                    <td>
                                        <div class="admin-users-doc__badges">
                                            @if(is_array($template->applicable_to) && count($template->applicable_to))
                                                @foreach($template->applicable_to as $role)
                                                    @php
                                                        $roleEnum = UserRole::tryFrom($role);
                                                    @endphp
                                                    @if($roleEnum)
                                                        <span class="admin-user-role-badge admin-user-role-badge--{{ $roleEnum->value }}">
                                                            {{ $roleEnum->title() }}
                                                        </span>
                                                    @endif
                                                @endforeach
                                            @else
                                                <span class="text-body-secondary small">{{ __('Nobody — no role selected') }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    {{-- Submissions, split by how they ended. --}}
                                    <td>
                                        <div class="kt-usage">
                                            <span class="kt-usage__total {{ $total > 0 ? '' : 'kt-usage__total--none' }}">{{ number_format($total) }}</span>
                                            <span class="kt-split" aria-hidden="true">
                                                @if($total > 0)
                                                    @if($approved > 0)
                                                        <span class="kt-split__seg kt-split__seg--approved" style="flex-grow: {{ $approved }}"></span>
                                                    @endif
                                                    @if($rejected > 0)
                                                        <span class="kt-split__seg kt-split__seg--rejected" style="flex-grow: {{ $rejected }}"></span>
                                                    @endif
                                                    @if($pending > 0)
                                                        <span class="kt-split__seg kt-split__seg--pending" style="flex-grow: {{ $pending }}"></span>
                                                    @endif
                                                @else
                                                    <span class="kt-split__seg kt-split__seg--empty"></span>
                                                @endif
                                            </span>
                                            <span class="kt-usage__note">
                                                @if($total > 0 && $approved === 0 && $rejected === 0)
                                                    {{-- Nothing has been decided yet, so a percentage would read as a verdict. --}}
                                                    {{ __('all still pending') }}
                                                @elseif($total > 0)
                                                    {{ __(':percent% approved', ['percent' => round($approved / $total * 100)]) }}
                                                @else
                                                    {{ __('never used') }}
                                                @endif
                                            </span>
                                        </div>
                                    </td>

                                    {{-- Last edited: when, and who, when the author is known. --}}
                                    <td>
                                        <span class="kt-lastedit__at">{{ $template->updated_at?->format('d M Y') }}</span>
                                        @if($template->updatedBy)
                                            <span class="kt-lastedit__by">{{ $template->updatedBy->name }}</span>
                                        @endif
                                    </td>

                                    @can('kyc-template-manage')
                                        {{-- Live: a real control, not a decorative switch. --}}
                                        <td>
                                            <form action="{{ route('admin.kyc.template.toggle', $template->id) }}" method="POST">
                                                @csrf
                                                @method('PATCH')
                                                <div class="form-check form-switch mb-0">
                                                    <input class="form-check-input coevs-switch"
                                                           type="checkbox"
                                                           role="switch"
                                                           id="template-live-{{ $template->id }}"
                                                           onchange="this.form.submit()"
                                                           @checked($template->status)
                                                           aria-label="{{ $template->status ? __('Pause :title', ['title' => $template->title]) : __('Make :title live', ['title' => $template->title]) }}">
                                                </div>
                                            </form>
                                        </td>

                                        <td class="text-end">
                                            <span class="kt-actions">
                                                {{--
                                                    One labelled action, then the ⋯. Three icon-only
                                                    buttons in a row made the reader decode a
                                                    magnifier, a pencil and a ⋯ before knowing what
                                                    the row offered. Edit carries the word because
                                                    it is the one used daily.
                                                --}}
                                                <button type="button"
                                                        class="kt-action edit-modal"
                                                        data-edit-url="{{ route('admin.kyc.template.edit', $template->id) }}"
                                                        aria-label="{{ __('Edit :title', ['title' => $template->title]) }}">
                                                    <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                                    <span>{{ __('Edit') }}</span>
                                                </button>
                                                {{--
                                                    Behind the ⋯: the occasional read, then the
                                                    destructive one under a divider. Delete sits
                                                    there by the same rule the page header applies
                                                    to its own controls — a destructive action one
                                                    pixel from an edit is one that happens by
                                                    accident.
                                                --}}
                                                <span class="dropdown">
                                                    <button type="button"
                                                            class="kt-iconbtn"
                                                            data-coreui-toggle="dropdown"
                                                            aria-expanded="false"
                                                            aria-label="{{ __('More actions for :title', ['title' => $template->title]) }}">
                                                        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            {{--
                                                                Preview opens the applicant's own
                                                                view of the form, read-only, so
                                                                checking what a template asks for
                                                                never risks saving it. Its handler
                                                                is delegated from the document, so
                                                                it still fires from inside this menu.
                                                            --}}
                                                            <button type="button"
                                                                    class="dropdown-item kt-preview-open"
                                                                    data-kt-title="{{ $template->title }}"
                                                                    data-kt-desc="{{ $template->description }}"
                                                                    data-kt-fields="{{ json_encode($previewFields) }}">
                                                                <i class="fa-solid fa-eye me-2" aria-hidden="true"></i>{{ __('Preview the form') }}
                                                            </button>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <button type="button"
                                                                    class="delete dropdown-item text-danger"
                                                                    data-url="{{ route('admin.kyc.template.destroy', $template->id) }}">
                                                                <i class="fa-solid fa-trash me-2" aria-hidden="true"></i>{{ __('Delete :title', ['title' => $template->title]) }}
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </span>
                                            </span>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="kt-tablefoot">
                        <i class="fa-solid fa-circle-info text-body-secondary" aria-hidden="true"></i>
                        <span class="kt-tablefoot__note">
                            {{ __('Deleting a template keeps the submissions made with it — the review screen falls back to the field names stored on each submission.') }}
                        </span>
                        <span class="kt-tablefoot__count">
                            {{ __(':templates templates · :fields fields · :submissions submissions', [
                                'templates'   => number_format($totalTemplates),
                                'fields'      => number_format($allFields->count()),
                                'submissions' => number_format($totalSubmissions),
                            ]) }}
                        </span>
                    </div>
                @else
                    <div class="admin-users-empty">
                        <x-admin-not-found
                            :title="__('No KYC templates found')"
                            :message="__('Create your first template to start collecting user verification details.')"
                            icon="fa-id-card"
                        />
                    </div>
                @endif
            </div>
        </section>
    </div>

    @can('kyc-template-manage')
        @include('backend.kyc.template.partials._create_template_modal')
        @include('backend.kyc.template.partials._edit_template_modal')

        {{--
            The applicant's own view of a template, read-only. Shares every
            class with the builder's live pane, so the two can never drift.
        --}}
        <div class="modal fade" id="previewTemplateModal" tabindex="-1" aria-labelledby="previewTemplateLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content kyc-template-content">
                    <div class="modal-header">
                        <div>
                            <span class="kt-eyebrow">{{ __('What the applicant sees') }}</span>
                            <h5 id="previewTemplateLabel" class="modal-title" data-kt-modal-title></h5>
                        </div>
                        <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div class="kt-preview">
                            <div class="kt-preview__card is-empty" data-kt-empty="{{ __('This template has no fields yet.') }}">
                                <div class="kt-preview__body" data-kt-preview>
                                    <div class="kt-preview__empty">{{ __('This template has no fields yet.') }}</div>
                                </div>
                                @if($requiresConsent)
                                    <label class="kt-preview__consent">
                                        <input type="checkbox" class="admin-check" disabled tabindex="-1">
                                        <span>{{ $consentText }}</span>
                                    </label>
                                @endif
                                <div class="kt-preview__submit" aria-hidden="true">{{ __('Submit for verification') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endcan
@endsection
@push('scripts')
    @can('kyc-template-manage')
        @include('backend.kyc.template.partials._script')
    @endcan
@endpush
