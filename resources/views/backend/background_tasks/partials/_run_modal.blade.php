{{--
    Same id, form action, method and `task_key` field as before, so the
    controller and RunBackgroundTaskRequest need no change. What moved is the
    body: the option rows are built from the row's `data-task-options` payload
    instead of being two hard-coded fields, which is why every registry option
    is now reachable rather than just `limit` and `renewals`.
--}}
<div class="modal fade bt-dialog" id="run-task-modal" tabindex="-1" aria-labelledby="bt-run-title" aria-hidden="true"
     data-bt-modal-placeholder="{{ __('Pick a command to run') }}">
    <div class="modal-dialog modal-dialog-centered bt-dialog__dialog">
        <div class="modal-content bt-dialog__content">

            <div class="bt-dialog__header">
                <div class="bt-dialog__identity">
                    <span class="bt-dialog__tile" aria-hidden="true" data-bt-modal-tile>
                        <i class="fa-solid fa-play" data-bt-modal-icon></i>
                    </span>
                    <div class="bt-dialog__copy">
                        <span class="bt-dialog__eyebrow">@lang('Run command')</span>
                        <h2 class="bt-dialog__title" id="bt-run-title" data-bt-modal-label></h2>
                        <span class="bt-dialog__sig" dir="ltr" data-bt-modal-sig></span>
                    </div>
                </div>

                <button type="button" class="bt-dialog__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.background-tasks.run') }}" id="run-task-form">
                @csrf
                <input type="hidden" name="task_key" id="modal-task-key">

                <div class="bt-dialog__body">
                    {{-- Only shown when the dialog was opened without a task,
                         which is what the header's "Run task" does: every row
                         on Commands already names the one it means. --}}
                    <label class="bt-picker" data-bt-modal-picker hidden>
                        <span class="bt-picker__label">@lang('Command')</span>
                        <select class="bt-picker__select" data-bt-task-picker>
                            <option value="">@lang('Choose a command…')</option>
                            @foreach($tasks as $task)
                                <option value="{{ $task['key'] }}"
                                        data-task-key="{{ $task['key'] }}"
                                        data-task-label="{{ $task['label'] }}"
                                        data-task-desc="{{ $task['desc'] }}"
                                        data-task-sig="{{ $task['sig'] }}"
                                        data-task-options="{{ json_encode($task['options'], JSON_UNESCAPED_UNICODE) }}">{{ $task['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <p class="bt-dialog__desc" data-bt-modal-desc></p>

                    {{-- Filled by _index_scripts from the registry payload. --}}
                    <div class="bt-opts" data-bt-modal-options hidden></div>

                    {{-- The line that will actually run, assembled as the
                         options change. An operator who is unsure what a
                         switch does can read the flag it adds. --}}
                    <div class="bt-preview" aria-live="polite">
                        <span class="bt-preview__sigil" aria-hidden="true">$</span>
                        <span class="bt-preview__cmd" dir="ltr" data-bt-modal-preview></span>
                    </div>
                </div>

                <div class="bt-dialog__footer">
                    <span class="bt-dialog__note">@lang('Runs now · recorded in Run history')</span>
                    <div class="bt-dialog__actions">
                        <button type="button" class="bt-btn-ghost" data-coreui-dismiss="modal">@lang('Cancel')</button>
                        <button type="submit" class="bt-btn-primary" id="run-task-submit" data-bt-modal-submit>
                            <i class="fa-solid fa-play" aria-hidden="true"></i>@lang('Run now')
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
