/* Bousol - scripts communs */
(function () {
  'use strict';

  // Compte a rebours de session (informatif : le serveur fait foi)
  var el = document.getElementById('session-countdown');
  if (el) {
    var left = parseInt(el.getAttribute('data-ttl') || '3600', 10);
    var tick = function () {
      if (left <= 0) { el.textContent = '00:00'; return; }
      var m = Math.floor(left / 60), s = left % 60;
      el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
      left--;
      setTimeout(tick, 1000);
    };
    tick();
  }

  // Confirmation generique : <form data-confirm="Message">
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  });
})();
