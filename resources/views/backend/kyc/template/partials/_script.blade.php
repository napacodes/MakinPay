<script id="field-template" type="text/template">
    @include('backend.kyc.template.partials._fields', ['key' => '__INDEX__', 'field' => []])
</script>

<script>
    'use strict';

    var KT_TYPE_ICON = {
        text: 'fa-font',
        number: 'fa-hashtag',
        date: 'fa-calendar-days',
        file: 'fa-arrow-up-from-bracket',
        country: 'fa-globe'
    };

    /*
     * What the applicant reads in each control before they answer it.
     *
     * The pane used to print the words "Short text" and "A number" in grey
     * where the real form has an input, which told an admin what the field
     * was called in the builder rather than what the applicant would see.
     */
    var KT_PLACEHOLDER = {
        text: @json(__('Type an answer')),
        number: @json(__('A number')),
        date: @json(__('Select a date')),
        country: @json(__('Select a country'))
    };

    var KT_TEXT = {
        empty: @json(__('Name a field to see the form take shape.')),
        untitled: @json(__('Untitled template')),
        dropzone: @json(__('Choose a file or drop it here')),
        noFile: @json(__('No file chosen')),
        optional: @json(__('(optional)')),
        uploadHint: @json(__(':types · up to :size MB', ['types' => $uploadTypes ?? '', 'size' => $uploadMaxMb ?? 0]))
    };

    function ktEscape(value) {
        return $('<span>').text(value === null || value === undefined ? '' : value).html();
    }

    /*
     * Next free index for a new field row.
     *
     * Counting the rows is not enough: remove fields[0] from a two-field form
     * and the count is 1, which is the index the surviving row already uses —
     * the new field then overwrites it on submit. Take the highest index in
     * use and go past it.
     */
    function ktNextFieldIndex($container) {
        var highest = -1;

        $container.find('[name^="fields["]').each(function () {
            var match = /fields\[(\d+)\]/.exec($(this).attr('name') || '');

            if (match) {
                highest = Math.max(highest, parseInt(match[1], 10));
            }
        });

        return highest + 1;
    }

    /*
     * Renumber every row's fields[N][...] keys to match the order on screen.
     *
     * Dragging moves the markup but not the names, and PHP builds the posted
     * array from the keys — so without this a reordered list stores an
     * out-of-order object with holes in it rather than a plain list, and the
     * review screen reads the fields back in whatever order they were first
     * created in.
     */
    function ktReindexFields($container) {
        $container.find('.field-item').each(function (position) {
            $(this).find('[name^="fields["]').each(function () {
                var $input = $(this);
                var name = $input.attr('name') || '';

                $input.attr('name', name.replace(/^fields\[\d+\]/, 'fields[' + position + ']'));
            });
        });
    }

    /* One field, as the applicant will meet it. */
    function ktFieldMarkup(field) {
        var label = ktEscape(field.label);
        var type = field.type || 'text';
        var mark = field.required
            ? ' <span class="kt-preview__required">*</span>'
            : ' <span class="kt-preview__optional">' + ktEscape(KT_TEXT.optional) + '</span>';
        var wrapper = 'kt-preview__field' + (field.required ? '' : ' kt-preview__field--optional');
        var head = '<div class="' + wrapper + '"><span class="kt-preview__label">' + label + mark + '</span>';

        if (type === 'file') {
            /* An optional upload does not earn a whole dropzone. */
            if (!field.required) {
                return head +
                    '<span class="kt-preview__control kt-preview__control--file-compact">' +
                    ktEscape(KT_TEXT.noFile) +
                    '</span></div>';
            }

            return head +
                '<span class="kt-preview__control kt-preview__control--file">' +
                '<span class="kt-preview__icon" aria-hidden="true">' +
                '<i class="fa-solid fa-arrow-up-from-bracket"></i></span>' +
                '<span>' + ktEscape(KT_TEXT.dropzone) + '</span>' +
                '<span class="kt-preview__hint">' + ktEscape(KT_TEXT.uploadHint) + '</span>' +
                '</span></div>';
        }

        if (type === 'country') {
            return head +
                '<select class="kt-preview__control" disabled><option>' +
                ktEscape(KT_PLACEHOLDER.country) +
                '</option></select></div>';
        }

        return head +
            '<input type="text" class="kt-preview__control" readonly tabindex="-1"' +
            (type === 'number' ? ' inputmode="numeric"' : '') +
            ' placeholder="' + ktEscape(KT_PLACEHOLDER[type] || KT_PLACEHOLDER.text) + '">' +
            '</div>';
    }

    /*
     * Draw a whole applicant card: the template's own name and description,
     * then its fields. The consent line and the submit button are rendered in
     * Blade as siblings of [data-kt-preview] — they carry administrator
     * settings this script has no business inventing, and replacing this
     * element's innerHTML would wipe them out on every keystroke.
     */
    function ktRenderCard($card, title, description, fields) {
        var $body = $card.find('[data-kt-preview]');

        if (!$body.length) {
            return;
        }

        if (!fields.length) {
            /* A builder says "name a field"; a read-only preview has nobody to tell. */
            $card.addClass('is-empty');
            $body.html('<div class="kt-preview__empty">' +
                ktEscape($card.data('kt-empty') || KT_TEXT.empty) + '</div>');

            return;
        }

        var parts = ['<div><h3 class="kt-preview__title">' +
            ktEscape(title || KT_TEXT.untitled) + '</h3>'];

        if (description) {
            parts.push('<p class="kt-preview__desc">' + ktEscape(description) + '</p>');
        }

        parts.push('</div>');

        for (var i = 0; i < fields.length; i++) {
            parts.push(ktFieldMarkup(fields[i]));
        }

        $card.removeClass('is-empty');
        $body.html(parts.join(''));
    }

    /* Mirror the field rows into the applicant-facing preview. */
    function ktRenderPreview($form) {
        var $card = $form.find('.kt-preview__card');

        if (!$card.length) {
            return;
        }

        var fields = [];

        $form.find('.field-item').each(function () {
            var $row = $(this);
            var label = ($row.find('.field-label-input').val() || '').trim();

            if (!label) {
                return;
            }

            fields.push({
                label: label,
                type: $row.find('.field-type-input').val() || 'text',
                required: $row.find('.field-required-input').is(':checked')
            });
        });

        ktRenderCard(
            $card,
            ($form.find('input[name="title"]').val() || '').trim(),
            ($form.find('input[name="description"]').val() || '').trim(),
            fields
        );
    }

    /* How many fields the form has, beside the word Fields. */
    function ktSyncFieldCount($form) {
        $form.find('[data-field-count]').text($form.find('.fields-container .field-item').length);
    }

    /* Keep the little type badge honest when the select changes. */
    function ktSyncBadge($row) {
        var type = $row.find('.field-type-input').val() || 'text';

        $row.find('.field-type-badge')
            .attr('class', 'kt-type kt-type--' + type + ' field-type-badge')
            .find('i')
            .attr('class', 'fa-solid ' + (KT_TYPE_ICON[type] || KT_TYPE_ICON.text));
    }

    /*
     * Drag to reorder, on the library the admin layout already loads.
     *
     * The order here is the order on the review screen, so it is a real
     * control and not decoration: onEnd renumbers the posted keys and redraws
     * the preview, which is the pane that proves the move took.
     */
    function ktBindSortable($form) {
        if (typeof window.Sortable === 'undefined') {
            return;
        }

        $form.find('.fields-container').each(function () {
            if (this.ktSortable) {
                return;
            }

            this.ktSortable = window.Sortable.create(this, {
                handle: '.kt-field__handle',
                draggable: '.field-item',
                animation: 150,
                ghostClass: 'kt-field--ghost',
                dragClass: 'kt-field--drag',
                onEnd: function () {
                    var $container = $(this.el);

                    ktReindexFields($container);
                    ktRenderPreview($container.closest('form'));
                }
            });
        });
    }

    $(document).on('click', '.add-new-field', function () {
        var $form = $(this).closest('form');
        var $container = $form.find('.fields-container');
        var index = ktNextFieldIndex($container);
        var markup = $('#field-template').html().replace(/__INDEX__/g, index);
        var $row = $(markup);

        var requested = $(this).data('field-type');

        if (requested) {
            $row.find('.field-type-input').val(requested);
        }

        $container.append($row);
        ktSyncBadge($row);
        $row.find('.field-label-input').trigger('focus');
        ktSyncFieldCount($form);
        ktRenderPreview($form);
    });

    $(document).on('click', '.remove-field', function () {
        var $form = $(this).closest('form');

        $(this).closest('.field-item').remove();
        ktSyncFieldCount($form);
        ktRenderPreview($form);
    });

    $(document).on('input change', '.field-label-input, .field-type-input, .field-required-input', function () {
        var $row = $(this).closest('.field-item');

        ktSyncBadge($row);
        ktRenderPreview($row.closest('form'));
    });

    /* The card's own heading is the template's name, so it follows the typing. */
    $(document).on('input', '[data-kt-form] input[name="title"], [data-kt-form] input[name="description"]', function () {
        ktRenderPreview($(this).closest('form'));
    });

    /* Everything a builder form needs once its markup is on the page. */
    function ktInitForm($form) {
        if (!$form.length) {
            return;
        }

        ktBindSortable($form);
        ktSyncFieldCount($form);
        ktRenderPreview($form);

        /* Identity the edit body carries on its form root, lifted to the header. */
        var $modal = $form.closest('.modal');

        if ($form.is('[data-kt-title]') && $modal.length) {
            $modal.find('[data-kt-modal-title]').text($form.data('kt-title') || '');
            $modal.find('[data-kt-modal-saved]').text($form.data('kt-saved') || '');
        }
    }

    $(document).on('shown.coreui.modal', '#createTemplateModal, #editTemplateModal', function () {
        $(this).find('form').each(function () {
            ktInitForm($(this));
        });
    });

    /*
     * The edit body is fetched by editFormByModal and dropped in from an AJAX
     * callback, which lands after `shown.coreui.modal` has already fired —
     * every one of the three things above would then run against an empty
     * container. Watch the container instead of guessing when it fills.
     */
    (function () {
        var host = document.getElementById('edit-template-form');

        if (!host || typeof window.MutationObserver === 'undefined') {
            return;
        }

        new window.MutationObserver(function () {
            ktInitForm($(host).find('form').first());
        }).observe(host, { childList: true });
    })();

    /*
     * Preview from the list: the same card the builder draws, read-only, so a
     * reviewer can check what a template asks for without opening the editor
     * and risking a save.
     */
    $(document).on('click', '.kt-preview-open', function () {
        var $modal = $('#previewTemplateModal');

        if (!$modal.length) {
            return;
        }

        var fields = $(this).data('kt-fields');

        $modal.find('[data-kt-modal-title]').text($(this).data('kt-title') || KT_TEXT.untitled);

        ktRenderCard(
            $modal.find('.kt-preview__card'),
            $(this).data('kt-title'),
            $(this).data('kt-desc'),
            Array.isArray(fields) ? fields : []
        );

        var ModalClass = (window.coreui && window.coreui.Modal) || (window.bootstrap && window.bootstrap.Modal);

        if (ModalClass) {
            ModalClass.getOrCreateInstance($modal[0]).show();
        }
    });

    editFormByModal('editTemplateModal', 'edit-template-form');
</script>
