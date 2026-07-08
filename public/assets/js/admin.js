/* 007DataDestroyer — Backend-Interaktionen
 * Blendet je nach gewähltem Modus die passenden Felder ein/aus.
 */
(function () {
    'use strict';

    var mode = document.getElementById('mode');
    if (!mode) { return; }

    var rowDate    = document.getElementById('row-date');
    var rowWeekday = document.getElementById('row-weekday');
    var hourlyHint = document.getElementById('hourlyHint');

    function update() {
        var v = mode.value;
        if (rowDate)    { rowDate.classList.toggle('is-hidden', v !== 'once'); }
        if (rowWeekday) { rowWeekday.classList.toggle('is-hidden', v !== 'weekly'); }
        if (hourlyHint) { hourlyHint.classList.toggle('is-hidden', v !== 'hourly'); }
    }

    mode.addEventListener('change', update);
    update();
})();
