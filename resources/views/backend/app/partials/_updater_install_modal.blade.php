@can('project-updater-manage')
    @if($installable)
        {{--
            The pre-flight list is the same environment check the server runs,
            printed one last time at the point of no return. It is deliberately
            not a second, friendlier set of checks: an operator who reads
            "writable" here and then watches the copy phase fail on permissions
            has been told two different things by the same page.
        --}}
        <div class="pu-overlay" id="pu-install-modal" data-open="false">
            <div class="pu-modal project-updater" data-state="modal" role="dialog" aria-modal="true"
                aria-labelledby="pu-install-modal-title">

                <header class="pu-modal__head">
                    <span class="pu-modal__icon" aria-hidden="true"><x-icon name="app" width="20" height="20"/></span>
                    <div class="pu-modal__head-copy">
                        <h2 class="pu-modal__title" id="pu-install-modal-title">
                            {{ __('Install v:version', ['version' => $latest->version]) }}
                        </h2>
                        <p class="pu-modal__sub">
                            {{ __('From v:current · maintenance mode is on for the whole install', ['current' => $currentVersion]) }}
                        </p>
                    </div>
                    <button type="button" class="pu-modal__close" data-action="close-install-modal"
                        aria-label="{{ __('Close') }}">&times;</button>
                </header>

                <div class="pu-modal__body">
                    <div class="pu-preflight">
                        <div class="pu-preflight__head">{{ __('Pre-flight') }}</div>
                        @foreach($checks as $check)
                            <div class="pu-preflight__row">
                                <span class="pu-preflight__icon pu-preflight__icon--{{ $check['status'] ? 'ok' : 'fail' }}" aria-hidden="true">
                                    <i class="fas {{ $check['status'] ? 'fa-check' : 'fa-xmark' }}"></i>
                                </span>
                                <span class="pu-preflight__label">{{ $check['label'] }}</span>
                                <span class="pu-preflight__value pu-mono" dir="ltr">{{ $check['value'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    <form id="pu-install-form" action="{{ route('admin.app.updater.install', $latest) }}" method="POST">
                        @csrf
                        <input type="hidden" name="backup_database_storage" id="pu-install-backup-hidden" value="1">

                        <label class="pu-switch" for="pu-install-backup">
                            <input type="checkbox" id="pu-install-backup" class="pu-switch__input" checked>
                            <span class="pu-switch__track" aria-hidden="true"><span class="pu-switch__knob"></span></span>
                            <span class="pu-switch__copy">
                                <span class="pu-switch__label">{{ __('Backup database and storage folder') }}</span>
                                <span class="pu-switch__hint">
                                    {{ __('Recommended. Saved to') }}
                                    <span class="pu-mono" dir="ltr">storage/app/{{ trim((string) config('project_updater.backups_path'), '/') }}/</span>
                                </span>
                            </span>
                        </label>

                        <div class="pu-switch pu-switch--fixed" aria-disabled="true">
                            <span class="pu-switch__track is-on is-locked" aria-hidden="true"><span class="pu-switch__knob"></span></span>
                            <span class="pu-switch__copy">
                                <span class="pu-switch__label">{{ __('Enable maintenance mode during install') }}</span>
                                <span class="pu-switch__hint">{{ __('Required. Customers see the maintenance page until the install completes.') }}</span>
                            </span>
                        </div>
                    </form>

                    <div class="pu-note pu-note--danger" id="pu-install-nobackup" hidden>
                        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                        <span>
                            <strong>{{ __('Without a backup there is nothing to restore from.') }}</strong>
                            {{ __('Only skip this if you already took your own snapshot in the last hour.') }}
                        </span>
                    </div>

                    {{-- Pointless advice on a demo install, where that download is refused. --}}
                    @unless($isDemo)
                        <div class="pu-note pu-note--warning">
                            <i class="fas fa-download" aria-hidden="true"></i>
                            <span>
                                <strong>{{ __('Important: download a local backup before updating') }}</strong>
                                {{ __('A snapshot on the same server does not survive a server failure. Use Generate recovery backup to keep a copy on your own machine.') }}
                            </span>
                        </div>
                    @endunless
                </div>

                <footer class="pu-modal__foot">
                    <button type="button" class="pu-btn pu-btn--ghost" data-action="close-install-modal">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" class="pu-btn pu-btn--primary" id="pu-install-confirm"
                        data-label-backup="{{ __('Back up & install') }}"
                        data-label-plain="{{ __('Install without backup') }}">
                        <i class="fas fa-rocket" aria-hidden="true"></i> <span class="label">{{ __('Back up & install') }}</span>
                    </button>
                </footer>
            </div>
        </div>
    @endif
@endcan
