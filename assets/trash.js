/**
 * Trash AddOn - Toast fuer das Sofort-Rueckgaengig.
 *
 * Zaehlt die verbleibende Frist herunter und blendet den Hinweis danach aus.
 * Reine Anzeige: ob die Ruecknahme noch moeglich ist, entscheidet der Server.
 */
(function () {
    'use strict';

    function dismiss(box) {
        if (box.dataset.trashUndoGone) {
            return;
        }
        box.dataset.trashUndoGone = '1';
        box.classList.add('is-leaving');
        box.classList.remove('is-expiring');

        var remove = function () {
            if (box.parentNode) {
                box.remove();
            }
        };

        box.addEventListener('animationend', remove, {once: true});
        // Falls die Animation unterdrueckt ist (prefers-reduced-motion)
        window.setTimeout(remove, 500);
    }

    function start(box) {
        if (box.dataset.trashUndoBound) {
            return;
        }
        box.dataset.trashUndoBound = '1';

        // REDAXO bettet die Rueckgabe des Extension Points in seine eigene
        // Erfolgsmeldung ein. Da der Toast fixiert positioniert ist, bliebe
        // dort ein leerer farbiger Balken zurueck - deshalb den Toast ans
        // body haengen und eine leer gewordene Huelle entfernen.
        var host = box.parentNode;
        if (host && host !== document.body) {
            document.body.appendChild(box);

            if (host.classList && host.classList.contains('alert') && host.innerText.trim() === '') {
                host.remove();
            }
        }

        var counter = box.querySelector('.trash-undo-countdown');
        var output = box.querySelector('.trash-undo-seconds');
        var closeBtn = box.querySelector('.trash-undo-close');
        var left = parseInt((counter && counter.dataset.timeout) || '30', 10);

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                dismiss(box);
            });
        }

        // Escape schliesst den Toast
        var onKey = function (e) {
            if (e.key === 'Escape' && !box.dataset.trashUndoGone) {
                dismiss(box);
                document.removeEventListener('keydown', onKey);
            }
        };
        document.addEventListener('keydown', onKey);

        if (!output || isNaN(left)) {
            return;
        }

        var timer = window.setInterval(function () {
            left -= 1;

            if (left <= 0) {
                window.clearInterval(timer);
                dismiss(box);
                return;
            }

            output.textContent = String(left);
            if (left <= 10) {
                box.classList.add('is-expiring');
            }
        }, 1000);
    }

    function init() {
        var boxes = document.querySelectorAll('.trash-undo-message');
        for (var i = 0; i < boxes.length; i++) {
            start(boxes[i]);
        }
    }

    document.addEventListener('DOMContentLoaded', init);
    // REDAXO laedt Backend-Seiten teilweise per pjax nach
    if (window.jQuery) {
        window.jQuery(document).on('rex:ready', init);
    }
    // Falls das Script erst nach dem DOM geladen wird
    if (document.readyState !== 'loading') {
        init();
    }
})();
