"use strict";

/*
 * The KYC review workspace's document viewer.
 *
 * Zoom, rotate, switch document, compare two side by side, and step through
 * the queue with the arrow keys. Everything here is view state: nothing in
 * this file can decide a submission.
 */
(function () {
    var viewer = document.querySelector('[data-kw-viewer]');

    if (!viewer) {
        return;
    }

    var stage = viewer.querySelector('[data-kw-stage]');
    var zoomLabel = viewer.querySelector('[data-kw-zoom-label]');
    var compareButton = viewer.querySelector('[data-kw-compare]');
    var download = viewer.querySelector('[data-kw-download]');
    var provenance = viewer.querySelector('[data-kw-label]');
    var docs = Array.prototype.slice.call(viewer.querySelectorAll('[data-kw-doc]'));
    var tabs = Array.prototype.slice.call(viewer.querySelectorAll('[data-kw-tab]'));

    var zoom = 1;
    var rotation = 0;

    function paint() {
        viewer.querySelectorAll('[data-kw-image]').forEach(function (image) {
            image.style.transform = 'scale(' + zoom + ') rotate(' + rotation + 'deg)';
        });

        if (zoomLabel) {
            zoomLabel.textContent = Math.round(zoom * 100) + '%';
        }
    }

    function show(index) {
        docs.forEach(function (doc, i) {
            doc.classList.toggle('is-active', i === index);
        });

        tabs.forEach(function (tab, i) {
            tab.classList.toggle('is-active', i === index);
            tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });

        /*
         * The download button and the provenance line follow the document on
         * screen. A button that always saved the first upload is worse than
         * no button: the reviewer gets a file believing it is the one they
         * were looking at.
         */
        var active = docs[index];

        if (!active) {
            return;
        }

        if (download && active.getAttribute('data-kw-url')) {
            download.setAttribute('href', active.getAttribute('data-kw-url'));
        }

        if (provenance && active.getAttribute('data-kw-title')) {
            provenance.textContent = active.getAttribute('data-kw-title');
        }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            if (stage) {
                stage.classList.remove('is-comparing');
            }

            if (compareButton) {
                compareButton.setAttribute('aria-pressed', 'false');
            }

            show(index);
        });
    });

    viewer.querySelectorAll('[data-kw-zoom]').forEach(function (button) {
        button.addEventListener('click', function () {
            var step = parseInt(button.getAttribute('data-kw-zoom'), 10) || 0;

            // Clamped: past these the document is either a postage stamp or
            // a single pixel, and neither is a review.
            zoom = Math.min(3, Math.max(0.5, zoom + step * 0.25));
            paint();
        });
    });

    var rotateButton = viewer.querySelector('[data-kw-rotate]');

    if (rotateButton) {
        rotateButton.addEventListener('click', function () {
            rotation = (rotation + 90) % 360;
            paint();
        });
    }

    if (compareButton && stage) {
        compareButton.addEventListener('click', function () {
            var comparing = stage.classList.toggle('is-comparing');

            compareButton.setAttribute('aria-pressed', comparing ? 'true' : 'false');
        });
    }

    /*
     * Arrow keys walk the queue, but only when the reviewer is not typing —
     * otherwise moving the caret in the remarks field would navigate away and
     * lose what they had written.
     */
    document.addEventListener('keydown', function (event) {
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        var active = document.activeElement;

        if (active && (active.matches('input, textarea, select') || active.isContentEditable)) {
            return;
        }

        var link = null;

        if (event.key === 'ArrowRight') {
            link = document.querySelector('[data-kw-next]');
        } else if (event.key === 'ArrowLeft') {
            link = document.querySelector('[data-kw-prev]');
        }

        if (link) {
            event.preventDefault();
            window.location.href = link.getAttribute('href');
        }
    });

    paint();
})();
