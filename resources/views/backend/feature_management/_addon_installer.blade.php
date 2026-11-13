{{--
    The add-on installer (docs/ui/addons/README.md §F) and the dialog that
    confirms installing an add-on already on disk or uninstalling one.

    Nothing here pretends: Verify and Requirements show what the server found
    in the uploaded package, the Install step is an indeterminate bar for as
    long as the one install request runs (no invented percentages), and the
    outcomes repeat the reasons the backend gave. Every sentence is written
    here, in the admin's locale; feature-management.js only fills slots.
--}}
@php
    $installerAdmin = auth('admin')->user();
    $needsOtp       = (bool) ($installerAdmin?->two_factor_enabled && filled($installerAdmin?->google2fa_secret));
    $canFeatures    = (bool) $installerAdmin?->can('feature-manage');
    $tokenUrl       = fn (string $name): string => route($name, ['token' => str_repeat('T', 40)]);
    $slugUrl        = fn (string $name): string => route($name, ['slug' => 'addon-slug-placeholder']);

    $installerSteps = [
        'source'       => __('Upload'),
        'verify'       => __('Verify'),
        'requirements' => __('Requirements'),
        'license'      => __('License'),
        'install'      => __('Install'),
        'enable'       => __('Enable'),
    ];

    $installerUnits = [
        'feature'    => [__(':count feature'), __(':count features')],
        'menu'       => [__(':count admin menu entry'), __(':count admin menu entries')],
        'permission' => [__(':count permission'), __(':count permissions')],
        'migration'  => [__(':count database migration'), __(':count database migrations')],
        'file'       => [__(':count file'), __(':count files')],
    ];

    $installerCopy = [
        'units'            => $installerUnits,
        'eyebrow'          => __('Install add-on · step :step of :total'),
        'eyebrowUpdate'    => __('Update add-on · step :step of :total'),
        'titleDefault'     => __('Install add-on'),
        'titleNamed'       => __(':name v:version'),
        'continue'         => __('Continue'),
        'cancel'           => __('Cancel'),
        'close'            => __('Close'),
        'checking'         => __('Checking the package…'),
        'notZip'           => __('Choose a .zip file.'),
        'tooLarge'         => __('The package is larger than 50 MB.'),
        'network'          => __('The server did not answer. Check the connection and try again.'),
        'signature'        => __('Signed by Coevs'),
        'signatureHint'    => __('Every file matches the signature · release key :fingerprint'),
        'package'          => __('Package'),
        'packageHint'      => __(':size · :files'),
        'compatible'       => __('Works with DigiKash :core'),
        'incompatible'     => __('Needs DigiKash :required or later · this site runs :core'),
        'noConflicts'      => __('No conflicts'),
        'noConflictsHint'  => __('Its feature keys are not used by core or another add-on.'),
        'adds'             => __('This adds :list.'),
        'updateNote'       => __('Version :from is installed. This replaces it with :to; the current files are kept aside and put back if the update fails.'),
        'blocking'         => __('Needed to install'),
        'runtime'          => __('Needed to switch it on'),
        'recheck'          => __('Re-check'),
        'licenseFor'       => __('Will be licensed to :domain'),
        'formatOk'         => __('Valid format'),
        'formatBad'        => __('Not a purchase code yet'),
        'activateLater'    => __('Activate later'),
        'activateContinue' => __('Activate & continue'),
        'readyInstall'     => __('Ready to install :name v:version'),
        'readyUpdate'      => __('Ready to update :name to v:version'),
        'migrationsNone'   => __('It has no database migrations. It cannot be stopped once started.'),
        'migrationsSome'   => __('Runs :migrations in one request. It cannot be stopped once started.'),
        'install'          => __('Install'),
        'update'           => __('Update'),
        'elapsed'          => __('Keep this window open · :seconds s'),
        'installed'        => __(':name v:version is installed'),
        'featuresOn'       => __('Turn on its :features for customers'),
        'finish'           => __('Finish'),
        'finishLater'      => __('Finish later'),
        'licenseBound'     => __('License bound to :domain.'),
        'successOn'        => __(':name is installed and on'),
        'successOff'       => __(':name is installed'),
        'successText'      => __(':list added. Its features stay off until you turn them on in the Features tab.'),
        'successTextOn'    => __(':list added, and its features are on.'),
        'goFeatures'       => __('Go to Features'),
        'openAddon'        => __('Open add-on'),
        'blockedTitle'     => __('Install paused'),
        'blockedText'      => __('Nothing has been installed. Fix the items below on the server, then re-check. The package stays uploaded.'),
        'stoppedText'      => __('Nothing has been installed. Fix the items below on the server, then install it from its card.'),
        'cancelInstall'    => __('Cancel install'),
        'conflictTitle'    => __('This package cannot be installed'),
        'conflictText'     => __('It claims feature keys that are already taken. Nothing was installed and the upload was deleted.'),
        'refusedTitle'     => __('This package was refused'),
        'refusedText'      => __('Only packages signed with the Coevs release key can be installed. Use the .zip from your order.'),
        'failedTitle'      => __('Install failed — rolled back'),
        'failedText'       => __('A migration failed. The migrations this attempt ran were rolled back, and the add-on is marked as failed so it can be retried from its card.'),
        'stillOffTitle'    => __(':name is installed but still off'),
        'stillOffText'     => __('It could not be switched on yet. Fix this, then switch it on from its card:'),
    ];
@endphp

<div class="feature-mgmt-modal-backdrop" data-addon-installer-backdrop></div>
<div class="feature-mgmt-modal feature-mgmt-installer"
     data-addon-installer
     aria-hidden="true"
     data-upload-url="{{ route('admin.features.addons.packages.store') }}"
     data-check-url="{{ $tokenUrl('admin.features.addons.packages.check') }}"
     data-install-url="{{ $tokenUrl('admin.features.addons.packages.install') }}"
     data-discard-url="{{ $tokenUrl('admin.features.addons.packages.discard') }}"
     data-status-url="{{ $slugUrl('admin.features.addons.status') }}"
     data-features-url="{{ $slugUrl('admin.features.addons.features') }}"
     data-addon-url="{{ route('admin.features.index', ['tab' => 'addons', 'addon' => 'addon-slug-placeholder']) }}"
     data-features-tab-url="{{ route('admin.features.index') }}"
     data-domain="{{ request()->getHost() }}"
     data-can-features="{{ $canFeatures ? '1' : '0' }}"
     data-copy="{{ json_encode($installerCopy, JSON_UNESCAPED_UNICODE) }}">
    <div class="feature-mgmt-modal__dialog feature-mgmt-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="addonInstallerTitle">
        <button type="button" class="feature-mgmt-modal__close" data-installer-close aria-label="{{ __('Close') }}">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>

        <header class="feature-mgmt-installer__head">
            <span class="feature-mgmt-installer__eyebrow" data-installer-eyebrow></span>
            <h3 class="feature-mgmt-modal__title" id="addonInstallerTitle" data-installer-title></h3>
            <ol class="feature-mgmt-steps" data-installer-steps>
                @foreach($installerSteps as $stepKey => $stepLabel)
                    <li class="feature-mgmt-steps__item" data-installer-step="{{ $stepKey }}">
                        <span class="feature-mgmt-steps__dot" aria-hidden="true">
                            <span class="feature-mgmt-steps__num" dir="ltr">{{ $loop->iteration }}</span>
                            <i class="fa-solid fa-check feature-mgmt-steps__check"></i>
                        </span>
                        <span class="feature-mgmt-steps__label">{{ $stepLabel }}</span>
                    </li>
                @endforeach
            </ol>
        </header>

        <div class="feature-mgmt-installer__body" aria-live="polite">
            <section data-installer-panel="source">
                <label class="feature-mgmt-dropzone" data-installer-dropzone>
                    <input type="file" class="feature-mgmt-dropzone__input" accept=".zip,application/zip" data-installer-file>
                    <span class="feature-mgmt-dropzone__icon" aria-hidden="true"><i class="fa-solid fa-file-zipper"></i></span>
                    <span class="feature-mgmt-dropzone__title">{{ __('Drop the add-on .zip here, or choose a file') }}</span>
                    <span class="feature-mgmt-dropzone__hint">{{ __('Up to 50 MB · must be signed by Coevs') }}</span>
                </label>
                <div class="feature-mgmt-installer__file" data-installer-file-row hidden>
                    <i class="fa-solid fa-file-zipper" aria-hidden="true"></i>
                    <span class="feature-mgmt-installer__file-name" dir="ltr" data-installer-file-name></span>
                    <span class="feature-mgmt-installer__file-size" dir="ltr" data-installer-file-size></span>
                </div>
                <p class="feature-mgmt-installer__error" data-installer-error hidden></p>
            </section>

            <section data-installer-panel="verify" hidden>
                <div data-installer-verify></div>
                <p class="feature-mgmt-installer__note" data-installer-adds></p>
                <p class="feature-mgmt-installer__note feature-mgmt-installer__note--brand" data-installer-update hidden></p>
            </section>

            <section data-installer-panel="requirements" hidden>
                <div data-installer-requirements></div>
            </section>

            <section data-installer-panel="license" hidden>
                <label class="feature-mgmt-field">
                    <span class="feature-mgmt-field__label">{{ __('Purchase code') }}</span>
                    <input type="text" class="form-control feature-mgmt-field__input feature-mgmt-field__input--mono" dir="ltr"
                           data-installer-code autocomplete="off" spellcheck="false" maxlength="80"
                           placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <span class="feature-mgmt-field__hint" data-installer-code-state></span>
                </label>
                <p class="feature-mgmt-installer__note" data-installer-license-for></p>
            </section>

            <section data-installer-panel="install" hidden>
                <div data-installer-ready>
                    <p class="feature-mgmt-installer__lead" data-installer-ready-title></p>
                    <p class="feature-mgmt-installer__note" data-installer-ready-text></p>
                    <div class="feature-mgmt-installer__confirm">
                        <label class="feature-mgmt-field">
                            <span class="feature-mgmt-field__label">{{ __('Your password') }}</span>
                            <input type="password" class="form-control feature-mgmt-field__input" data-installer-password autocomplete="current-password">
                            <span class="feature-mgmt-field__error" data-installer-error-for="password" hidden></span>
                        </label>
                        @if($needsOtp)
                            <label class="feature-mgmt-field">
                                <span class="feature-mgmt-field__label">{{ __('Authenticator code') }}</span>
                                <input type="text" class="form-control feature-mgmt-field__input feature-mgmt-field__input--mono" dir="ltr"
                                       data-installer-otp inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                                <span class="feature-mgmt-field__error" data-installer-error-for="otp" hidden></span>
                            </label>
                        @endif
                        <span class="feature-mgmt-field__error" data-installer-error-for="purchase_code" hidden></span>
                    </div>
                </div>
                <div class="feature-mgmt-installer__running" data-installer-running hidden>
                    <p class="feature-mgmt-installer__lead">{{ __('Installing…') }}</p>
                    <span class="feature-mgmt-progress" role="progressbar" aria-label="{{ __('Installing…') }}"><span class="feature-mgmt-progress__fill"></span></span>
                    <p class="feature-mgmt-installer__note" data-installer-elapsed></p>
                </div>
            </section>

            <section data-installer-panel="enable" hidden>
                <div class="feature-mgmt-installer__effect">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <span data-installer-installed></span>
                </div>
                <p class="feature-mgmt-installer__note" data-installer-license-result hidden></p>
                <label class="feature-mgmt-installer__toggle">
                    <span class="feature-mgmt-sw">
                        <input class="feature-mgmt-sw__input" type="checkbox" role="switch" data-installer-enable checked>
                        <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                    </span>
                    <span class="feature-mgmt-installer__toggle-copy">
                        <strong>{{ __('Switch the add-on on now') }}</strong>
                        <span>{{ __('Its admin screens and background work start on the next request.') }}</span>
                    </span>
                </label>
                <label class="feature-mgmt-installer__toggle" data-installer-features-row>
                    <span class="feature-mgmt-sw {{ $canFeatures ? '' : 'is-disabled' }}">
                        <input class="feature-mgmt-sw__input" type="checkbox" role="switch" data-installer-features @disabled(! $canFeatures)>
                        <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                    </span>
                    <span class="feature-mgmt-installer__toggle-copy">
                        <strong data-installer-features-label></strong>
                        <span>{{ $canFeatures ? __('Off by default. You can turn them on later in the Features tab.') : __('Your role cannot switch features. An admin with Feature Management access can turn them on.') }}</span>
                    </span>
                </label>
            </section>

            <section data-installer-panel="outcome" hidden>
                <div class="feature-mgmt-installer__outcome" data-installer-outcome>
                    <span class="feature-mgmt-installer__outcome-icon" aria-hidden="true">
                        <i class="fa-solid fa-circle-check feature-mgmt-installer__glyph feature-mgmt-installer__glyph--success"></i>
                        <i class="fa-solid fa-circle-pause feature-mgmt-installer__glyph feature-mgmt-installer__glyph--warning"></i>
                        <i class="fa-solid fa-circle-xmark feature-mgmt-installer__glyph feature-mgmt-installer__glyph--danger"></i>
                    </span>
                    <p class="feature-mgmt-installer__lead" data-installer-outcome-title></p>
                    <p class="feature-mgmt-installer__note" data-installer-outcome-text></p>
                    <ul class="feature-mgmt-installer__reasons" data-installer-outcome-list></ul>
                </div>
            </section>
        </div>

        <footer class="feature-mgmt-modal__actions feature-mgmt-installer__foot">
            <button type="button" class="feature-mgmt-btn feature-mgmt-btn--ghost" data-installer-cancel></button>
            <span class="feature-mgmt-installer__spacer"></span>
            <button type="button" class="feature-mgmt-btn feature-mgmt-btn--ghost" data-installer-secondary hidden></button>
            <button type="button" class="feature-mgmt-btn feature-mgmt-btn--primary" data-installer-primary></button>
        </footer>
    </div>
</div>

{{-- Installing an add-on already in addons/, or uninstalling one: a plain form, re-opened with its errors after a refused post. --}}
@php
    $operationMode  = old('operation');
    $operationOpen  = $errors->hasAny(['password', 'otp', 'confirm_slug', 'keep_data']) && in_array($operationMode, ['install', 'uninstall'], true);
    $operationCopy  = [
        'units'          => ['migration' => $installerUnits['migration']],
        'installTitle'   => __('Install :name'),
        'installText'    => __('Runs its database migrations and registers its permissions and features. Its features stay off until you turn them on.'),
        'installSubmit'  => __('Install'),
        'uninstallTitle' => __('Uninstall :name'),
        'uninstallText'  => __('Its admin screens, routes and background work stop at once. Its folder stays in addons/, so it can be installed again.'),
        'deleteNone'     => __('Deletes its permissions and feature rows. It has no database migrations. This cannot be undone.'),
        'deleteSome'     => __('Reverts its :migrations and deletes what they created, with its permissions and feature rows. This cannot be undone.'),
        'typeSlug'       => __('Type :slug to confirm'),
        'submitKeep'     => __('Uninstall'),
        'submitDelete'   => __('Uninstall and delete data'),
    ];
@endphp
<div class="feature-mgmt-modal-backdrop" data-addon-operation-backdrop></div>
<div class="feature-mgmt-modal feature-mgmt-operation"
     data-addon-operation
     aria-hidden="true"
     data-install-url="{{ $slugUrl('admin.features.addons.install') }}"
     data-uninstall-url="{{ $slugUrl('admin.features.addons.destroy') }}"
     @if($operationOpen)
         data-reopen="{{ $operationMode }}"
         data-reopen-slug="{{ old('operation_slug') }}"
         data-reopen-name="{{ old('operation_name') }}"
         data-reopen-migrations="{{ (int) old('operation_migrations') }}"
         data-reopen-keep="{{ old('keep_data', '1') }}"
     @endif
     data-copy="{{ json_encode($operationCopy, JSON_UNESCAPED_UNICODE) }}">
    <div class="feature-mgmt-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="addonOperationTitle">
        <button type="button" class="feature-mgmt-modal__close" data-operation-close aria-label="{{ __('Close') }}">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>

        <form method="POST" action="" data-operation-form novalidate>
            @csrf
            <input type="hidden" name="_method" value="POST" data-operation-method>
            <input type="hidden" name="operation" value="" data-operation-mode>
            <input type="hidden" name="operation_slug" value="" data-operation-slug>
            <input type="hidden" name="operation_name" value="" data-operation-name>
            <input type="hidden" name="operation_migrations" value="0" data-operation-migrations>

            <div class="feature-mgmt-modal__header">
                <div class="feature-mgmt-modal__icon" aria-hidden="true">
                    <i class="fa-solid fa-triangle-exclamation feature-mgmt-modal__icon-glyph feature-mgmt-modal__icon-glyph--danger"></i>
                    <i class="fa-solid fa-puzzle-piece feature-mgmt-modal__icon-glyph feature-mgmt-modal__icon-glyph--success"></i>
                </div>
                <span class="feature-mgmt-modal__eyebrow">{{ __('Add-ons') }}</span>
            </div>

            <div class="feature-mgmt-modal__body">
                <h3 class="feature-mgmt-modal__title" id="addonOperationTitle" data-operation-title></h3>
                <p class="feature-mgmt-modal__text" data-operation-text></p>

                <fieldset class="feature-mgmt-choices" data-operation-uninstall hidden>
                    <legend class="visually-hidden">{{ __('What happens to its data') }}</legend>
                    <label class="feature-mgmt-choice">
                        <input type="radio" class="feature-mgmt-choice__input" name="keep_data" value="1" data-operation-keep checked>
                        <span class="feature-mgmt-choice__tile">
                            <span class="feature-mgmt-choice__title">{{ __('Keep its data') }}</span>
                            <span class="feature-mgmt-choice__hint">{{ __('Tables, permissions and feature settings stay, for a reinstall.') }}</span>
                        </span>
                    </label>
                    <label class="feature-mgmt-choice feature-mgmt-choice--danger">
                        <input type="radio" class="feature-mgmt-choice__input" name="keep_data" value="0" data-operation-delete>
                        <span class="feature-mgmt-choice__tile">
                            <span class="feature-mgmt-choice__title">{{ __('Delete its data') }}</span>
                            <span class="feature-mgmt-choice__hint" data-operation-delete-hint></span>
                        </span>
                    </label>

                    <label class="feature-mgmt-field" data-operation-typed hidden>
                        <span class="feature-mgmt-field__label" data-operation-typed-label></span>
                        <input type="text" class="form-control feature-mgmt-field__input feature-mgmt-field__input--mono" dir="ltr"
                               name="confirm_slug" autocomplete="off" spellcheck="false" data-operation-typed-input>
                        @error('confirm_slug')<span class="feature-mgmt-field__error">{{ $message }}</span>@enderror
                    </label>
                </fieldset>

                <div class="feature-mgmt-installer__confirm">
                    <label class="feature-mgmt-field">
                        <span class="feature-mgmt-field__label">{{ __('Your password') }}</span>
                        <input type="password" class="form-control feature-mgmt-field__input" name="password" autocomplete="current-password">
                        @error('password')<span class="feature-mgmt-field__error">{{ $message }}</span>@enderror
                    </label>
                    @if($needsOtp)
                        <label class="feature-mgmt-field">
                            <span class="feature-mgmt-field__label">{{ __('Authenticator code') }}</span>
                            <input type="text" class="form-control feature-mgmt-field__input feature-mgmt-field__input--mono" dir="ltr"
                                   name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                            @error('otp')<span class="feature-mgmt-field__error">{{ $message }}</span>@enderror
                        </label>
                    @endif
                </div>
            </div>

            <div class="feature-mgmt-modal__actions">
                <button type="button" class="feature-mgmt-btn feature-mgmt-btn--ghost" data-operation-close>{{ __('Cancel') }}</button>
                <button type="submit" class="feature-mgmt-btn feature-mgmt-btn--primary" data-operation-submit></button>
            </div>
        </form>
    </div>
</div>
