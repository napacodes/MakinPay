@php
    use App\Enums\KycRejectionReason;
    use App\Enums\KycStatus;
    use App\Support\KycQueueRow;
@endphp
@extends('backend.layouts.app')
@section('title', __('Review KYC submission'))

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('backend/css/kyc-queue.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/kyc-queue.css'))) }}">
    <link rel="stylesheet"
          href="{{ asset('backend/css/kyc-workspace.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/kyc-workspace.css'))) }}">
@endpush

@section('content')
    @php
        $user       = $submission->user;
        $avatarData = getUserAvatarDetails($user->first_name, $user->last_name);
        $row        = KycQueueRow::for($submission);
        $signal     = $row->providerSignal();
        $detail     = $row->detailHtml();
        $wait       = $row->waiting($slaHours);
        $checks     = $submission->auto_result['checks'] ?? [];
        $canDecide  = auth('admin')->user()?->can('kyc-action') && $submission->status === KycStatus::PENDING;

        /* "Pending" is the ledger's word for the row; this screen is the
           decision, so it says what is being waited on. */
        $statusLabel = $submission->status === \App\Enums\KycStatus::PENDING
            ? __('Awaiting decision')
            : ($submission->status?->label() ?? __('Unknown'));

        $documents = $row->documents();

        /*
         * Everything the template asked for, and nothing it did not - a
         * seeder flag is not a KYC answer.
         *
         * Nothing is hidden for looking like the account card. A stated name,
         * email or country that DISAGREES with the account is the single most
         * useful thing on this page, so the two are shown side by side and the
         * account card says plainly that its values are the account's.
         */
        $details = $row->statedDetails();

        /*
         * The pill took the amber tone whatever the status was, so an already
         * approved submission read "Approved" in the colour of a warning.
         * KycStatus::color() cannot be fed in directly: it answers `primary`
         * for PENDING, which PageHeader::TONES does not carry and would
         * normalise away to neutral.
         */
        $statusTone = match ($submission->status) {
            KycStatus::APPROVED => 'success',
            KycStatus::REJECTED => 'danger',
            default             => 'warning',
        };

        /*
         * A check has three outcomes, not two. The pass/flag test had no
         * third state, so a check the engine never ran — stored as null, or
         * as the literal "not_run" — was painted as a red failure, telling
         * the reviewer the provider had found something when it had been
         * asked nothing.
         */
        $checkState = function ($outcome): array {
            $notRun = ['', 'null', 'not_run', 'not run', 'skipped', 'n/a', 'not applicable'];
            $text   = is_scalar($outcome) ? trim((string) $outcome) : (string) json_encode($outcome);

            if ($outcome === null || in_array(strtolower($text), $notRun, true)) {
                return ['state' => 'skip', 'icon' => 'fa-circle-minus', 'value' => __('not run')];
            }

            return in_array(strtolower($text), ['1', 'true', 'pass', 'passed', 'clear', 'ok'], true)
                ? ['state' => 'pass', 'icon' => 'fa-circle-check', 'value' => $text]
                : ['state' => 'flag', 'icon' => 'fa-circle-exclamation', 'value' => $text];
        };
    @endphp

    <div class="admin-users-page admin-kyc-page">
        <x-page-header
            variant="compact"
            :title="title($user->name)"
            :subtitle="$user->email"
            :badge="'#'.$submission->id"
            :avatar="$user->avatar ? asset($user->avatar) : null"
            :initials="$avatarData['initials']"
            :breadcrumbs="[
                ['label' => __('KYC verification'), 'url' => route('admin.kyc.pending')],
                ['label' => __('Pending reviews'), 'url' => route('admin.kyc.pending', $queryBack)],
                ['label' => __('Review')],
            ]"
            :back="route('admin.kyc.pending', $queryBack)"
            :back-label="__('Queue')"
            :status="['tone' => $statusTone, 'label' => $statusLabel]"
            :meta="[
                ['label' => $submission->kycTemplate?->title ?? __('Unknown template')],
                ['label' => __('Item :position of :total', ['position' => $position, 'total' => $queueTotal])],
            ]"
            class="mb-3"
        >
            <x-slot:actions>
                {{-- Kept in place when there is nowhere to go: a control that
                     vanishes moves the two beside it on every other row. --}}
                @if($previous)
                    <a href="{{ route('admin.kyc.review', array_merge($queryBack, ['submission' => $previous->id])) }}"
                       class="btn btn-outline-secondary" data-ph-icon rel="prev" data-kw-prev
                       aria-label="{{ __('Previous submission') }}">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    </a>
                @else
                    <span class="btn btn-outline-secondary" data-ph-icon
                          aria-disabled="true" aria-label="{{ __('Previous submission') }}">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    </span>
                @endif
                @if($next)
                    <a href="{{ route('admin.kyc.review', array_merge($queryBack, ['submission' => $next->id])) }}"
                       class="btn btn-outline-secondary" data-ph-icon rel="next" data-kw-next
                       aria-label="{{ __('Next submission') }}">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </a>
                @else
                    <span class="btn btn-outline-secondary" data-ph-icon
                          aria-disabled="true" aria-label="{{ __('Next submission') }}">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </span>
                @endif
                <a href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'statistics']) }}"
                   class="btn btn-outline-secondary">{{ __('Open profile') }}</a>
            </x-slot:actions>
        </x-page-header>

        @if($duplicate)
            @php
                /*
                 * What the finding is, given what became of the other
                 * submission. The fingerprint matches whatever state the twin
                 * is in, and "already approved elsewhere" is the sentence
                 * that stops a reviewer — "also waiting in this queue" is not.
                 */
                $twinUrl  = route('admin.kyc.review', ['submission' => $duplicate['submission_id']]);
                $twinName = '<a href="'.e($twinUrl).'">'.e('@'.$duplicate['username']).'</a>';
                $twinWhen = $duplicate['decided_at']?->format('j M Y');

                $twinLine = match (true) {
                    $duplicate['status'] === KycStatus::APPROVED && $twinWhen !== null => __('The :document fingerprint matches an approved submission from :username, approved :when.', ['document' => strtolower($duplicate['field_label']), 'username' => $twinName, 'when' => $twinWhen]),
                    $duplicate['status'] === KycStatus::APPROVED                       => __('The :document fingerprint matches an approved submission from :username.', ['document' => strtolower($duplicate['field_label']), 'username' => $twinName]),
                    $duplicate['status'] === KycStatus::REJECTED && $twinWhen !== null => __('The :document fingerprint matches a rejected submission from :username, rejected :when.', ['document' => strtolower($duplicate['field_label']), 'username' => $twinName, 'when' => $twinWhen]),
                    $duplicate['status'] === KycStatus::REJECTED                       => __('The :document fingerprint matches a rejected submission from :username.', ['document' => strtolower($duplicate['field_label']), 'username' => $twinName]),
                    default                                                            => __('The :document fingerprint matches a submission from :username that is still waiting for a decision.', ['document' => strtolower($duplicate['field_label']), 'username' => $twinName]),
                };
            @endphp
            <div class="kq-flag mb-3" role="alert">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <span class="kq-flag__copy">
                    <strong>{{ __('Duplicate document.') }}</strong>
                    {!! $twinLine !!}
                    {{ __('Resolve before deciding.') }}
                </span>
                <a href="{{ $twinUrl }}" class="kq-flag__action">{{ __('Open the other submission') }}</a>
            </div>
        @endif

        <div class="kw-workspace">
        <div class="kw {{ $documents === [] ? 'kw--nodocs' : '' }}">
            {{-- Documents get the room: they are the subject of the review. --}}
            <section class="kw-panel" aria-label="{{ __('Documents') }}" data-kw-viewer>
                <div class="kw-toolbar">
                    @if(count($documents) > 0)
                        <div class="kw-tabs" role="tablist" aria-label="{{ __('Submitted documents') }}">
                            @foreach($documents as $index => $document)
                                <button type="button"
                                        role="tab"
                                        class="kw-tab {{ $index === 0 ? 'is-active' : '' }}"
                                        aria-selected="{{ $index === 0 ? 'true' : 'false' }}"
                                        data-kw-tab="{{ $index }}">
                                    {{ $document['label'] }}
                                </button>
                            @endforeach
                        </div>

                        <div class="kw-tools">
                            <button type="button" class="kw-iconbtn" data-kw-zoom="-1" aria-label="{{ __('Zoom out') }}">
                                <i class="fa-solid fa-minus" aria-hidden="true"></i>
                            </button>
                            <span class="kw-zoom" data-kw-zoom-label>100%</span>
                            <button type="button" class="kw-iconbtn" data-kw-zoom="1" aria-label="{{ __('Zoom in') }}">
                                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="kw-iconbtn" data-kw-rotate aria-label="{{ __('Rotate') }}">
                                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                            </button>
                            @if(count($documents) > 1)
                                {{-- The one control an icon cannot carry on its
                                     own, so it is the one that is labelled. --}}
                                <button type="button" class="kw-toolbtn" data-kw-compare
                                        aria-pressed="false" title="{{ __('Show documents side by side') }}">
                                    <i class="fa-solid fa-table-columns" aria-hidden="true"></i>
                                    <span>{{ __('Compare') }}</span>
                                </button>
                            @endif
                            <a class="kw-iconbtn" data-kw-download href="{{ $documents[0]['url'] }}" download
                               aria-label="{{ __('Download document') }}">
                                <i class="fa-solid fa-download" aria-hidden="true"></i>
                            </a>
                        </div>
                    @endif
                </div>

                <div class="kw-stage" data-kw-stage>
                    @forelse($documents as $index => $document)
                        <figure class="kw-doc {{ $index === 0 ? 'is-active' : '' }}" data-kw-doc="{{ $index }}"
                                data-kw-url="{{ $document['url'] }}" data-kw-title="{{ $document['label'] }}">
                            @if($document['is_image'])
                                <a href="{{ $document['url'] }}" target="_blank" rel="noopener">
                                    <img src="{{ $document['url'] }}" alt="{{ $document['label'] }}" data-kw-image loading="lazy">
                                </a>
                            @else
                                <a href="{{ $document['url'] }}" class="kw-doc__file" download>
                                    <i class="fa-regular fa-file-lines fa-2x" aria-hidden="true"></i>
                                    <span>
                                        <strong class="d-block">{{ basename($document['path']) }}</strong>
                                        <small>{{ __('Download attachment') }}</small>
                                    </span>
                                </a>
                            @endif
                        </figure>
                    @empty
                        <p class="kw-stage__empty">{{ __('This submission carries no uploaded documents.') }}</p>
                    @endforelse
                </div>

                {{-- Provenance, reduced to what this installation can say.
                     The files are on the public disk and nothing scans them,
                     so the strip states when the upload arrived and which
                     field it was filed under, and claims nothing else. --}}
                @if(count($documents) > 0)
                    <div class="kw-stagefoot">
                        <span class="kw-stagefoot__label" data-kw-label>{{ $documents[0]['label'] }}</span>
                        <span class="kw-stagefoot__at">
                            {{ $submission->created_at
                                ? __('Uploaded :at', ['at' => $submission->created_at->format('d M, H:i')])
                                : __('Upload time not recorded') }}
                        </span>
                    </div>
                @endif
            </section>

            <aside class="kw-evidence" aria-label="{{ __('Evidence') }}">
                {{-- One card, one engine: its tile, its verdict, when it
                     answered, what it checked and the reference it answered
                     under. --}}
                <section class="kw-panel">
                    <div class="kw-card__head">
                        <span class="kw-tile kw-tile--{{ $signal['accent'] }}" aria-hidden="true">{{ $signal['initials'] }}</span>
                        <h2 class="kw-card__title">
                            {{ $signal['name']
                                ? __(':provider verdict', ['provider' => $signal['name']])
                                : __('Provider signal') }}
                        </h2>
                        <span class="kq-pill kq-pill--{{ $signal['tone'] }}">
                            {{ $signal['label'] }}
                            @if($signal['confidence'])
                                <span class="kq-pill__score">{{ $signal['confidence'] }}</span>
                            @endif
                        </span>
                        {{-- updated_at, not verified_at: nothing has verified
                             a submission that is still waiting. --}}
                        @if($submission->updated_at)
                            <span class="kw-card__aside">{{ $submission->updated_at->format('d M, H:i') }}</span>
                        @endif
                    </div>

                    @if(filled($checks))
                        <ul class="kw-checks">
                            @foreach($checks as $name => $outcome)
                                @php $check = $checkState($outcome); @endphp
                                <li class="kw-check is-{{ $check['state'] }}">
                                    <i class="fa-solid {{ $check['icon'] }}" aria-hidden="true"></i>
                                    <span class="kw-check__name">{{ ucwords(str_replace('_', ' ', (string) $name)) }}</span>
                                    <span class="kw-check__value">{{ $check['value'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- One footer: the engine's own sentence, then the
                         reference it filed the answer under. --}}
                    @if($detail || filled($submission->provider_reference))
                        <div class="kw-card__foot">
                            @if($detail)
                                <span>{!! $detail !!}</span>
                            @endif
                            @if(filled($submission->provider_reference))
                                <span class="kw-card__foot-ref">{{ __('Reference') }} <span class="kw-ref">{{ $submission->provider_reference }}</span></span>
                            @endif
                        </div>
                    @endif
                </section>

                <section class="kw-panel">
                    <div class="kw-card__head">
                        <h2 class="kw-card__title">{{ __('Account at a glance') }}</h2>
                        <a class="kw-card__aside"
                           href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'statistics']) }}">{{ __('Full profile') }}</a>
                    </div>

                    {{-- A reviewer deciding on an identity is also deciding
                         about an account that already has a history, and that
                         history used to be two screens away. --}}
                    <div class="kw-block">
                        <dl class="kw-facts kw-facts--3up">
                            <div>
                                <dt>{{ __('Account age') }}</dt>
                                <dd>{{ $account['age'] }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Lifetime volume') }}</dt>
                                <dd>{{ trans_choice(':count payment|:count payments', $account['transactions'], ['count' => number_format($account['transactions'])]) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Held balance') }}</dt>
                                {{-- A count, never a sum: wallets hold several
                                     currencies and adding them together
                                     produces a number that means nothing. --}}
                                <dd>{{ trans_choice(':count wallet|:count wallets', $account['wallets'], ['count' => number_format($account['wallets'])]) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Earlier attempts') }}</dt>
                                <dd class="{{ $account['earlier_rejected'] > 0 ? 'is-warning' : '' }}">
                                    {{ $account['earlier_rejected'] > 0
                                        ? trans_choice(':count rejected|:count rejected', $account['earlier_rejected'], ['count' => $account['earlier_rejected']])
                                        : __('None') }}
                                </dd>
                            </div>
                            <div>
                                <dt>{{ __('Usual sign-in') }}</dt>
                                <dd class="{{ $account['country_matches'] === true ? 'is-success' : ($account['country_matches'] === false ? 'is-warning' : '') }}">
                                    {{ $account['usual_country'] ?: __('No sign-in history') }}
                                </dd>
                            </div>
                            {{-- Rendered only where the marketplace runs:
                                 "None" on a site with no marketplace is a
                                 claim about a feature it does not have. --}}
                            @if($account['disputes'] !== null)
                                <div>
                                    <dt>{{ __('Open disputes') }}</dt>
                                    <dd class="{{ $account['disputes'] > 0 ? 'is-danger' : '' }}">
                                        {{ $account['disputes'] > 0 ? number_format($account['disputes']) : __('None') }}
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    {{-- The comparison is sign-in country against the
                         ACCOUNT's country. Nothing in this codebase extracts
                         an issuing country off the document, so the sentence
                         must not claim to have read one. --}}
                    @if($account['country_matches'] !== null)
                        <div class="kw-card__foot">
                            {{ $account['country_matches']
                                ? __('Usual sign-in country matches the account country, :country.', ['country' => $user->country])
                                : __('Usual sign-in is :signin, but the account says :account.', ['signin' => $account['usual_country'], 'account' => $user->country]) }}
                        </div>
                    @endif
                </section>

                @if(filled($details))
                    <section class="kw-panel">
                        <div class="kw-card__head">
                            <h2 class="kw-card__title">{{ __('Stated details') }}</h2>
                            <span class="kw-card__hint">{{ __('as the applicant typed them') }}</span>
                        </div>
                        <div class="kw-block">
                            <dl class="kw-facts">
                                @foreach($details as $label => $value)
                                    <div>
                                        <dt>{{ $label }}</dt>
                                        <dd>{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </section>
                @endif

                {{-- Consent and the trail sit side by side beneath it, as the
                     board draws them: two short cards, not one tall one. --}}
                <div class="kw-pair">
                    @if($submission->hasVerificationConsent())
                        <section class="kw-panel">
                        <div class="kw-block">
                            <h2 class="kw-block__title">{{ __('Consent on record') }}</h2>
                            @php $consentWhen = $submission->consent_accepted_at->format('d M, H:i'); @endphp
                            <p class="kq-signal__detail mb-0">
                                @if($submission->consent_ip)
                                    {!! __('Accepted :when from :ip.', [
                                        'when' => e($consentWhen),
                                        'ip'   => '<span class="kw-ref">'.e($submission->consent_ip).'</span>',
                                    ]) !!}
                                @else
                                    {{ __('Accepted :when.', ['when' => $consentWhen]) }}
                                @endif
                            </p>
                            @if(filled($submission->consent_text))
                                <blockquote class="kw-quote mt-2 mb-0">{!! nl2br(e($submission->consent_text)) !!}</blockquote>
                                <p class="kq-signal__detail mb-0">{{ __('Stored with the submission, so later edits to the settings wording never change what this person saw.') }}</p>
                            @endif
                        </div>
                        </section>
                    @endif

                    {{-- The trail still renders with no logs when the reader
                         can decide, because the step it is missing is theirs. --}}
                    @if($submission->reviewLogs->isNotEmpty() || $canDecide)
                        <section class="kw-panel">
                        <div class="kw-block">
                            <h2 class="kw-block__title">{{ __('Audit trail') }}</h2>
                            <ol class="kw-trail">
                                @foreach($submission->reviewLogs as $entry)
                                    <li class="kw-trail__item">
                                        <span class="kw-trail__dot kw-trail__dot--{{ $entry->action->tone() }}" aria-hidden="true"></span>
                                        <span class="kw-trail__body">
                                            <span class="kw-trail__label">{{ $entry->action->label() }}</span>
                                            <span class="kw-trail__meta">
                                                {{ $entry->admin?->name ?? __('Automated pipeline') }}
                                                @if($entry->reason_code)
                                                    · {{ $entry->reason_code->label() }}
                                                @endif
                                                · {{ $entry->created_at?->format('d M, H:i') }}
                                            </span>
                                        </span>
                                    </li>
                                @endforeach

                                @if($canDecide)
                                    <li class="kw-trail__item kw-trail__item--pending">
                                        <span class="kw-trail__dot kw-trail__dot--pending" aria-hidden="true"></span>
                                        <span class="kw-trail__body">
                                            <span class="kw-trail__label">{{ __('Your decision') }}</span>
                                            <span class="kw-trail__meta">{{ __('pending') }}</span>
                                        </span>
                                    </li>
                                @endif
                            </ol>
                        </div>
                        </section>
                    @endif
                </div>
            </aside>
        </div>

        @if($canDecide)
            <section class="kw-panel kw-decision mt-3" aria-label="{{ __('Decision') }}">
                <form action="{{ route('admin.kyc.request-action') }}" method="POST">
                    @csrf
                    <input type="hidden" name="submission_id" value="{{ $submission->id }}">

                    {{--
                        Enter must not decide.

                        Approve and Reject are both submits in one form, told
                        apart by name="action". The browser's implicit
                        submission runs the FIRST submit in tree order, which
                        is Reject — so a reviewer who had already chosen a
                        reason and pressed Enter while typing remarks rejected
                        the applicant, having clicked nothing. The server was
                        no backstop: reason_code was set, so the request
                        validated and the rejection notification went out.

                        A disabled default button is the standards answer. On
                        implicit submission the spec runs the default button's
                        activation behaviour, and a disabled button has none,
                        so Enter now does nothing. Deciding takes a deliberate
                        click, which is the whole point of this page.
                    --}}
                    <button type="submit" class="kw-decide__noenter" disabled tabindex="-1" aria-hidden="true"></button>

                    <div class="kw-decide">
                        <label class="kw-decide__reason">
                            <span class="form-label">
                                {{ __('Reason') }}
                                <span class="text-body-secondary small">{{ __('required to reject') }}</span>
                            </span>
                            <select name="reason_code" class="form-select @error('reason_code') is-invalid @enderror">
                                <option value="">{{ __('Select a reason…') }}</option>
                                @foreach(KycRejectionReason::options() as $value => $label)
                                    <option value="{{ $value }}" @selected(old('reason_code') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('reason_code')
                                <span class="invalid-feedback d-block">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="kw-decide__remarks">
                            <span class="form-label">
                                {{ __('Remarks') }}
                                <span class="text-body-secondary small">{{ __('sent to the applicant') }}</span>
                            </span>
                            <input type="text" name="remarks" class="form-control"
                                   value="{{ old('remarks') }}"
                                   placeholder="{{ __('What should this person do next?') }}">
                        </label>

                        <div class="kw-decide__buttons">
                            <button type="submit" name="action" value="reject" class="btn btn-danger">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i> {{ __('Reject') }}
                            </button>
                            <button type="submit" name="action" value="approve" class="btn btn-success">
                                <i class="fa-solid fa-check" aria-hidden="true"></i> {{ __('Approve') }}
                            </button>
                        </div>
                    </div>

                    <p class="kw-note mb-0">
                        {{ __('Every decision is written to the KYC review log with your name, the reason code and the previous status. Approving marks the account KYC-verified; rejecting lets the applicant submit again.') }}
                    </p>
                </form>
            </section>
        @else
            <p class="kw-note mt-3 rounded">
                {{ __('Read-only. This submission has already been decided, or you do not hold the KYC decision permission.') }}
            </p>
        @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/kyc-workspace.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/kyc-workspace.js'))) }}"></script>
@endpush
