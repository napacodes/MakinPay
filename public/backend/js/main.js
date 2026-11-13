"use strict";

$(function () {
    // Initialize Tooltips
    $('[data-coreui-toggle="tooltip"]').each(function () {
        new coreui.Tooltip(this);
    });

    // Initialize Popovers
    $('[data-coreui-toggle="popover"]').each(function () {
        new coreui.Popover(this);
    });

    // Header Shadow on Scroll
    const $header = $('header.header');
    $(document).on('scroll', function () {
        $header.toggleClass('shadow-sm', $(document).scrollTop() > 0);
    });

    // Initialize Tagify
    const $tagInput = $('.tags-evs');
    if ($tagInput.length) {
        new Tagify($tagInput[0]);
    }

    // Initialize Summernote
    initializeSummernote('.summernote');

    // Initialize Clipboard.js
    const clipboard = new ClipboardJS('.copyNow');
    clipboard.on('success', function (e) {
        const button = e.trigger;
        const tooltip = coreui.Tooltip.getInstance(button);
        if (tooltip) {
            tooltip.setContent({'.tooltip-inner': 'Copied!'});
            tooltip.show();
        }
    });

    $(document).on('click', '.sidebar-quick-link[href]', function (event) {
        const link = this;
        const href = link.getAttribute('href');

        if (!href || href === '#' || link.target === '_blank' || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        const tooltip = coreui.Tooltip.getInstance(link);

        if (tooltip) {
            tooltip.hide();
        }

        window.setTimeout(function () {
            if (event.isDefaultPrevented()) {
                window.location.assign(href);
            }
        }, 0);
    });

    // Auto Slugify Title Inputs
    $(document).on('input', '.title-to-slug', function () {
        const $this = $(this);
        const $target = $($this.data('slug-target'));
        $target.val(slugify($this.val()));
    });

    // Scroll to Active Sidebar Menu Item (Once)
    const $activeSidebarItem = $('.sidebar .nav-link.active');
    if ($activeSidebarItem.length) {
        $activeSidebarItem[0].scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }

    /*
     * Keep every admin table action column visually consistent with the
     * All Gift Cards table while preserving each module's existing forms,
     * permissions, modal triggers, and delegated event handlers.
     */
    const normalizeAdminActionLabel = function (value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase();
    };

    const actionLabels = [
        normalizeAdminActionLabel(document.body.dataset.adminActionLabel),
        normalizeAdminActionLabel(document.body.dataset.adminActionsLabel),
        normalizeAdminActionLabel('Action'),
        normalizeAdminActionLabel('Actions')
    ].filter(Boolean);

    const adminActionTone = function ($control) {
        const signature = [
            $control.attr('class'),
            $control.attr('data-action-tone')
        ].filter(Boolean).join(' ').toLocaleLowerCase();

        if (/(danger|delete|remove|reject|cancel|decline|ban)/.test(signature)) {
            return 'danger';
        }

        if (/(success|approve|activate|accept)/.test(signature)) {
            return 'success';
        }

        if (/(warning|pause|freeze|suspend)/.test(signature)) {
            return 'warning';
        }

        if (/(btn-secondary|btn-outline-secondary|btn-light(?:\s|$)|muted|locked|disabled)/.test(signature)) {
            return 'secondary';
        }

        return 'primary';
    };

    const enhanceAdminTableActions = function (scope) {
        const $tables = $(scope).is('table') ? $(scope) : $(scope).find('table');

        $tables.each(function () {
            const table = this;
            const actionColumnIndexes = [];

            $(table).find('thead tr').each(function () {
                $(this).children('th').each(function (columnIndex) {
                    const $heading = $(this);
                    const label = normalizeAdminActionLabel($heading.text());

                    if ($heading.is('[data-admin-action-column]') || actionLabels.includes(label)) {
                        actionColumnIndexes.push(columnIndex);
                        $heading.addClass('admin-table-actions-heading');
                    }
                });
            });

            $(table).find('tbody tr').each(function () {
                $(this).children('td').each(function (columnIndex) {
                    const $cell = $(this);
                    const label = normalizeAdminActionLabel($cell.attr('data-label'));

                    if ($cell.is('[data-admin-action-column]') || actionLabels.includes(label)) {
                        actionColumnIndexes.push(columnIndex);
                    }
                });
            });

            [...new Set(actionColumnIndexes)].forEach(function (columnIndex) {
                $(table).find('tbody tr').each(function () {
                    const cell = this.cells[columnIndex];

                    if (!cell || cell.colSpan > 1) {
                        return;
                    }

                    const $cell = $(cell).addClass('admin-table-actions-cell');
                    const $controls = $cell.find('a, button').filter(function () {
                        return $(this).closest('.modal, .dropdown-menu').length === 0;
                    });

                    $controls.each(function () {
                        const $control = $(this);

                        $control.removeClass(
                            'admin-table-action--primary admin-table-action--success admin-table-action--warning admin-table-action--danger admin-table-action--secondary'
                        );

                        const tone = adminActionTone($control);

                        $control
                            .addClass('admin-table-action')
                            .addClass('admin-table-action--' + tone);

                        $control.closest('form').addClass('admin-table-actions__form');
                        $control.parentsUntil($cell[0]).filter('div, span').first().addClass('admin-table-actions');
                    });
                });
            });
        });
    };

    enhanceAdminTableActions(document);

    const adminTableObserver = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    enhanceAdminTableActions(node);
                    enhanceAdminTableActions($(node).closest('table'));
                }
            });
        });
    });

    adminTableObserver.observe(document.body, {
        childList: true,
        subtree: true
    });


});
document.addEventListener("DOMContentLoaded", () => {
    // 1️⃣ Override the internal _setActiveLink method so it does nothing
    if (coreui.Navigation && coreui.Navigation.prototype) {
        coreui.Navigation.prototype._setActiveLink = function() {
            /* no-op */
        };
    }

    // 2️⃣ Now (re)initialize your navigation component as usual
    document.querySelectorAll('[data-coreui="navigation"]')
        .forEach(el => {
            coreui.Navigation.getOrCreateInstance(el, {
                // you can still control collapse behavior…
                groupsAutoCollapse: true,
                // …but activeLinksExact no longer matters, since _setActiveLink is empty
            });
        });

    // 3️⃣ Sidebar Toggle Handler
    const sidebar = document.getElementById('sidebar');
    const toggleButton = document.getElementById('admin-header-sidebar-toggle');
    const menuIcon = toggleButton?.querySelector('.sidebar-toggle-icon--menu');
    const collapseIcon = toggleButton?.querySelector('.sidebar-toggle-icon--collapse');
    const expandIcon = toggleButton?.querySelector('.sidebar-toggle-icon--expand');

    if (sidebar && toggleButton) {
        const updateToggleIcons = function (isCollapsed) {
            const isDesktop = window.innerWidth >= 992;

            if (menuIcon) {
                menuIcon.classList.toggle('d-none', isDesktop);
            }

            if (collapseIcon && expandIcon) {
                if (!isDesktop) {
                    collapseIcon.classList.add('d-none');
                    expandIcon.classList.add('d-none');

                    return;
                }

                if (isCollapsed) {
                    collapseIcon.classList.add('d-none');
                    expandIcon.classList.remove('d-none');
                } else {
                    collapseIcon.classList.remove('d-none');
                    expandIcon.classList.add('d-none');
                }
            }
        };

        const syncToggleState = function () {
            const isDesktop = window.innerWidth >= 992;
            const isCollapsed = isDesktop && (
                sidebar.classList.contains('sidebar-narrow-unfoldable') || sidebar.classList.contains('sidebar-narrow')
            );

            toggleButton.setAttribute('aria-pressed', isCollapsed ? 'true' : 'false');
            toggleButton.classList.toggle('is-active', isCollapsed);
            updateToggleIcons(isCollapsed);
        };

        toggleButton.addEventListener('click', function (event) {
            event.preventDefault();

            if (window.innerWidth >= 992) {
                sidebar.classList.remove('sidebar-narrow');
                sidebar.classList.toggle('sidebar-narrow-unfoldable');
                syncToggleState();

                return;
            }

            openMobileSidebar(sidebar);
        });

        window.addEventListener('resize', syncToggleState);

        syncToggleState();
    }

    /*
     * The header search used to have its mobile overlay driven from here.
     * The search is a command palette now, and admin-palette.js owns opening
     * and closing it at every width - one panel, one script.
     */

    /*
     * Mobile sidebar open/close helpers.
     *
     * The header toggle (above) opens the sidebar; the in-sidebar
     * close button (`[data-sidebar-close]`) closes it. Both go
     * through `openMobileSidebar` / `closeMobileSidebar` so we have
     * a single place to handle every CoreUI version + degraded
     * environments where the CoreUI JS hasn't loaded yet.
     *
     * Fallback ladder, in order:
     *   1. coreui.Sidebar  — primary CoreUI 5 API
     *   2. coreui.Offcanvas — some CoreUI builds treat mobile
     *      sidebar as an Offcanvas under the hood
     *   3. Manual class toggle — last-resort guarantee that the
     *      close button always works even if all JS bindings fail
     */
    function openMobileSidebar(el) {
        if (!el) {
            return;
        }
        try {
            if (window.coreui && coreui.Sidebar) {
                coreui.Sidebar.getOrCreateInstance(el).show();
                return;
            }
            if (window.coreui && coreui.Offcanvas) {
                coreui.Offcanvas.getOrCreateInstance(el).show();
                return;
            }
        } catch (e) {
            /* fall through to manual */
        }
        el.classList.add('show');
    }

    function closeMobileSidebar(el) {
        if (!el) {
            return;
        }
        try {
            if (window.coreui && coreui.Sidebar) {
                coreui.Sidebar.getOrCreateInstance(el).hide();
                return;
            }
            if (window.coreui && coreui.Offcanvas) {
                coreui.Offcanvas.getOrCreateInstance(el).hide();
                return;
            }
        } catch (e) {
            /* fall through to manual */
        }
        el.classList.remove('show');
    }

    /*
     * Delegated close handler. Lives at document level so it works
     * regardless of when CoreUI initializes or whether the close
     * button is re-rendered by another partial.
     */
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-sidebar-close]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        const target = document.getElementById(trigger.getAttribute('aria-controls') || 'sidebar');
        closeMobileSidebar(target);
    });
});
