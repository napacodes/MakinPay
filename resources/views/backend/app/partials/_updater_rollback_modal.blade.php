@can('project-updater-manage')
    {{--
        One modal for every history row; the row's button fills in the version
        and the action URL. The copy's whole job is to stop an operator
        believing this undoes the update — it undoes the *code*, and the
        sentence about the database is the most important line on the page.
    --}}
    <div class="pu-overlay" id="pu-rollback-modal" data-open="false">
        <div class="pu-modal project-updater" data-state="modal" role="dialog" aria-modal="true"
            aria-labelledby="pu-rollback-modal-title">

            <header class="pu-modal__head">
                <span class="pu-modal__icon pu-modal__icon--warning" aria-hidden="true">
                    <i class="fas fa-rotate-left"></i>
                </span>
                <div class="pu-modal__head-copy">
                    <h2 class="pu-modal__title" id="pu-rollback-modal-title">
                        {{ __('Roll back') }} <span id="pu-rollback-version" class="pu-mono" dir="ltr"></span>
                    </h2>
                    <p class="pu-modal__sub" id="pu-rollback-sub"></p>
                </div>
                <button type="button" class="pu-modal__close" data-action="close-rollback-modal"
                    aria-label="{{ __('Close') }}">&times;</button>
            </header>

            <div class="pu-modal__body">
                <div class="pu-note pu-note--danger">
                    <i class="fas fa-database" aria-hidden="true"></i>
                    <span>
                        <strong>{{ __('The database is not rolled back.') }}</strong>
                        {{ __('Only the application files are restored. Every transaction, wallet movement and KYC decision recorded since the install stays exactly as it is, and any migration that release ran stays applied.') }}
                    </span>
                </div>

                <div class="pu-preflight">
                    <div class="pu-preflight__head">{{ __('What happens') }}</div>
                    @foreach([
                        {{-- Says what is kept, not that a button will undo it: the
                             way back to this release is to install it again. --}}
                        __('A snapshot of the current files is taken first and kept on disk'),
                        __('The site goes into maintenance mode while files are replaced'),
                        __('Files this release added are left in place, never deleted'),
                        __('Caches are cleared and the site comes back up'),
                    ] as $step)
                        <div class="pu-preflight__row">
                            <span class="pu-preflight__icon pu-preflight__icon--ok" aria-hidden="true">
                                <i class="fas fa-check"></i>
                            </span>
                            <span class="pu-preflight__label">{{ $step }}</span>
                        </div>
                    @endforeach
                </div>

                <p class="pu-card__hint">
                    {{ __('If the database needs putting back as well, the snapshot folder holds database.sql and a restore-note.txt describing how. That is done by hand, deliberately, because replaying it destroys newer data.') }}
                </p>
            </div>

            <footer class="pu-modal__foot">
                <button type="button" class="pu-btn pu-btn--ghost" data-action="close-rollback-modal">
                    {{ __('Cancel') }}
                </button>

                <form method="POST" action="" id="pu-rollback-form">
                    @csrf
                    {{-- Carries the release the operator actually looked at; the
                         controller refuses the post if the row has moved on. --}}
                    <input type="hidden" name="confirm_version" id="pu-rollback-confirm" value="">
                    <button type="submit" class="pu-btn pu-btn--danger" id="pu-rollback-submit">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i> {{ __('Roll back files') }}
                    </button>
                </form>
            </footer>
        </div>
    </div>
@endcan
