<?php
/**
 * 007DataDestroyer — Recovery-Seite (öffentlich, PIN-geschützt)
 *
 * Der große grüne Knopf. Wird er innerhalb eines aktiven Zeitfensters mit
 * gültiger PIN betätigt, gilt das Fenster als bestätigt und die Löschung wird
 * abgewendet. Diese Seite kann NUR vor der Löschung schützen, nie eine auslösen.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

// Ohne abgeschlossenes Setup keine Funktion.
if (Config::needsSetup()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif">007DataDestroyer ist noch nicht eingerichtet. Bitte zuerst das Backend aufrufen.</p>';
    exit;
}

Auth::startSession(); // für CSRF-Token dieser Seite

$cfg   = Config::load();
$sched = new Scheduler($cfg);

$message = '';
$success = false;

// --- Button-Betätigung verarbeiten (vor dem Lazy-Check) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $csrfOk = Auth::checkCsrf($_POST['csrf'] ?? null);
    $pin    = (string) ($_POST['pin'] ?? '');

    if (!$csrfOk) {
        $message = 'Sicherheits-Token abgelaufen. Bitte Seite neu laden.';
    } elseif (Auth::tooManyAttempts('pin')) {
        $secs = Auth::lockRemaining('pin');
        $message = 'Zu viele Fehlversuche. Bitte in ' . human_duration($secs) . ' erneut versuchen.';
    } elseif ($pin === '' || empty($cfg['recoveryPinHash']) || !password_verify($pin, $cfg['recoveryPinHash'])) {
        Auth::recordAttempt('pin');
        usleep(700000); // kleine Verzögerung erschwert automatisiertes Durchprobieren
        $message = 'Falsche PIN.';
    } else {
        Auth::clearAttempts('pin');
        if (empty($cfg['armed'])) {
            $message = 'System ist derzeit nicht scharf — keine Bestätigung nötig.';
            $success = true;
        } else {
            $current = $sched->currentWindow();
            if ($current === null) {
                $message = 'Aktuell ist kein Zeitfenster geöffnet — eine Bestätigung ist jetzt nicht wirksam.';
            } else {
                $state = Config::loadState();
                $confirmed = is_array($state['confirmedWindows'] ?? null) ? $state['confirmedWindows'] : [];
                if (!in_array($current['id'], $confirmed, true)) {
                    $confirmed[] = $current['id'];
                }
                $state['confirmedWindows'] = $confirmed;
                $state['lastConfirm'] = $sched->now()->format('c');
                Config::saveState($state);
                destroyer_log('confirm', 'Recovery bestätigt für Fenster ' . $current['id']);
                $message = 'Bestätigt. Die Daten sind für dieses Zeitfenster sicher.';
                $success = true;
            }
        }
    }
}

// --- Lazy-Check (Cron-Fallback), gedrosselt --------------------------------
$state = Config::loadState();
$lastCheck = $state['lastCheck'] ?? null;
$runLazy = true;
if ($lastCheck) {
    try {
        $diff = time() - (new DateTimeImmutable($lastCheck))->getTimestamp();
        $runLazy = $diff > 20; // höchstens alle 20 Sekunden
    } catch (Throwable $ex) {
        $runLazy = true;
    }
}
if ($runLazy) {
    Watcher::run();
    $cfg = Config::load(); // kann sich (armed) geändert haben
}

// --- Statusdaten für die Anzeige ------------------------------------------
$armed   = !empty($cfg['armed']);
$now     = $sched->now();
$current = $armed ? $sched->currentWindow($now) : null;
$next    = $armed ? $sched->nextWindow($now) : null;
$csrf    = Auth::csrfToken();

// Zeitpunkte als Unix-ms für den JS-Countdown.
$windowOpenUntil = $current ? ($current['end']->getTimestamp() * 1000) : 0;
$nextWindowStart = $next ? ($next['start']->getTimestamp() * 1000) : 0;
$nextWindowEnd   = $next ? ($next['end']->getTimestamp() * 1000) : 0;
$nowMs           = $now->getTimestamp() * 1000;
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Recovery — 007DataDestroyer</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="page-recover">
<main class="recover-wrap">
    <h1 class="sr-only">007DataDestroyer — Recovery</h1>
    <p class="brand" aria-hidden="true">007<span>DataDestroyer</span></p>

    <div class="status-line" id="statusLine"
         data-armed="<?= $armed ? '1' : '0' ?>"
         data-open-until="<?= (int) $windowOpenUntil ?>"
         data-next-start="<?= (int) $nextWindowStart ?>"
         data-next-end="<?= (int) $nextWindowEnd ?>"
         data-now="<?= (int) $nowMs ?>">
        <?php if (!$armed): ?>
            <span class="dot dot-idle"></span> System nicht scharf
        <?php elseif ($current): ?>
            <span class="dot dot-open"></span> Zeitfenster ist <strong>JETZT offen</strong>
        <?php else: ?>
            <span class="dot dot-armed"></span> Scharf — nächstes Fenster wird geladen…
        <?php endif; ?>
    </div>

    <?php if ($message !== ''): ?>
        <p class="flash <?= $success ? 'flash-ok' : 'flash-err' ?>" role="<?= $success ? 'status' : 'alert' ?>"><?= e($message) ?></p>
    <?php endif; ?>

    <form method="post" class="recover-form" autocomplete="off">
        <input type="hidden" name="action" value="confirm">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

        <label class="pin-label" for="pin">PIN</label>
        <input class="pin-input" id="pin" name="pin" type="password"
               inputmode="numeric" autocomplete="off" required
               aria-describedby="btnHint">

        <button type="submit" class="big-button" id="recoverBtn"
                aria-label="Recovery bestätigen — Selbstzerstörung abbrechen">
            <span class="big-button-label">RECOVERY</span>
        </button>

        <p class="btn-hint" id="btnHint">PIN eingeben und Knopf drücken, um die Löschung für das aktuelle Fenster abzuwenden.</p>
    </form>

    <p class="countdown" id="countdown"></p>
</main>
<script src="assets/js/recover.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
