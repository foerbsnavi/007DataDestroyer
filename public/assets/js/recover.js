/* 007DataDestroyer — Recovery-Countdown
 *
 * Zeigt live an, ob gerade ein Fenster offen ist bzw. wann das nächste beginnt.
 * Nutzt die Server-Zeit (data-now) als Referenz, um von einer falsch gestellten
 * Client-Uhr unabhängig zu sein.
 */
(function () {
    'use strict';

    var el = document.getElementById('statusLine');
    var cd = document.getElementById('countdown');
    if (!el || !cd) { return; }

    var armed     = el.getAttribute('data-armed') === '1';
    var openUntil = parseInt(el.getAttribute('data-open-until'), 10) || 0;
    var nextStart = parseInt(el.getAttribute('data-next-start'), 10) || 0;
    var serverNow = parseInt(el.getAttribute('data-now'), 10) || Date.now();

    // Offset zwischen Server- und Client-Uhr.
    var offset = serverNow - Date.now();
    function now() { return Date.now() + offset; }

    function fmt(ms) {
        if (ms < 0) { ms = 0; }
        var s = Math.floor(ms / 1000);
        var d = Math.floor(s / 86400); s -= d * 86400;
        var h = Math.floor(s / 3600);  s -= h * 3600;
        var m = Math.floor(s / 60);    s -= m * 60;
        var parts = [];
        if (d > 0) { parts.push(d + ' Tg'); }
        if (h > 0 || d > 0) { parts.push(h + ' Std'); }
        parts.push(m + ' Min');
        parts.push(s + ' Sek');
        return parts.join(' ');
    }

    var reloaded = false;
    function reloadOnce() {
        if (reloaded) { return; }
        reloaded = true;
        window.location.reload();
    }

    function tick() {
        if (!armed) {
            cd.textContent = '';
            return;
        }
        var t = now();
        if (openUntil > 0) {
            var rem = openUntil - t;
            if (rem <= 0) { reloadOnce(); return; }
            cd.textContent = 'Fenster schließt in ' + fmt(rem);
        } else if (nextStart > 0) {
            var rem2 = nextStart - t;
            if (rem2 <= 0) { reloadOnce(); return; }
            cd.textContent = 'Nächstes Fenster in ' + fmt(rem2);
        } else {
            cd.textContent = '';
        }
    }

    tick();
    setInterval(tick, 1000);

    // Optische Rückmeldung beim Absenden (keine blockierenden Dialoge).
    var form = document.querySelector('.recover-form');
    var btn = document.getElementById('recoverBtn');
    if (form && btn) {
        form.addEventListener('submit', function () {
            btn.classList.add('is-pressed');
        });
    }
})();
