/* Portail DEVDYNAMICS - JS commun */

(function() {
    'use strict';

    // ===== Countdown session (1 heure = 3600 sec) =====
    var SESSION_TTL = 3600;
    var elapsedKey = 'portail_last_seen';
    var now = Math.floor(Date.now() / 1000);
    sessionStorage.setItem(elapsedKey, String(now));

    function updateCountdown() {
        var el = document.getElementById('session-countdown');
        if (!el) return;
        var start = parseInt(sessionStorage.getItem(elapsedKey) || now, 10);
        var elapsed = Math.floor(Date.now() / 1000) - start;
        var remaining = Math.max(0, SESSION_TTL - elapsed);
        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        if (remaining <= 0) {
            window.location.href = '/portail/login.php?expired=1';
        }
    }
    setInterval(updateCountdown, 1000);
    updateCountdown();

    // ===== Confirmation suppression =====
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-confirm]');
        if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    // ===== Auto-save brouillon AJAX (toutes les 2 minutes) =====
    // Pour les formulaires longs avec data-autosave="endpoint"
    document.querySelectorAll('form[data-autosave]').forEach(function(form) {
        var url = form.getAttribute('data-autosave');
        var indicator = form.querySelector('.autosave-indicator');
        setInterval(function() {
            var fd = new FormData(form);
            fd.append('autosave', '1');
            fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) {
                    if (indicator) {
                        indicator.textContent = 'Brouillon sauvegarde a ' + new Date().toLocaleTimeString();
                    }
                })
                .catch(function() {});
        }, 120000);
    });
})();
