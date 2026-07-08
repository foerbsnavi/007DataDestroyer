<?php
/**
 * 007DataDestroyer — Backend (Setup-Wizard, Dashboard, Einstellungen)
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();

// Backend niemals im Browser-/Proxy-Cache ablegen (enthält Cron-Token, Protokoll).
header('Cache-Control: no-store, private');

/** Kleiner Helfer: Flash-Nachricht setzen und weiterleiten (Post-Redirect-Get). */
function redirect_with(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: index.php');
    exit;
}

/* =========================================================================
 * 1) SETUP-WIZARD (wenn noch kein Admin-Passwort gesetzt ist)
 * ========================================================================= */
if (Config::needsSetup()) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
        if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
            $error = 'Sicherheits-Token abgelaufen. Bitte erneut versuchen.';
        } else {
            $pw   = (string) ($_POST['password'] ?? '');
            $pw2  = (string) ($_POST['password2'] ?? '');
            $pin  = (string) ($_POST['pin'] ?? '');
            $pin2 = (string) ($_POST['pin2'] ?? '');
            $tz   = (string) ($_POST['timezone'] ?? 'Europe/Berlin');

            if (strlen($pw) < 8) {
                $error = 'Das Admin-Passwort muss mindestens 8 Zeichen haben.';
            } elseif ($pw !== $pw2) {
                $error = 'Die Passwörter stimmen nicht überein.';
            } elseif (!preg_match('/^\d{6,10}$/', $pin)) {
                $error = 'Die Recovery-PIN muss aus 6–10 Ziffern bestehen.';
            } elseif ($pin !== $pin2) {
                $error = 'Die PINs stimmen nicht überein.';
            } elseif (!in_array($tz, timezone_identifiers_list(), true)) {
                $error = 'Ungültige Zeitzone.';
            } else {
                $cfg = Config::defaults();
                $cfg['adminPasswordHash'] = password_hash($pw, PASSWORD_DEFAULT);
                $cfg['recoveryPinHash']   = password_hash($pin, PASSWORD_DEFAULT);
                $cfg['timezone']          = $tz;
                $cfg['cronToken']         = random_token(24);
                $cfg['createdAt']         = date('c');
                $cfg['armed']             = false;
                Config::save($cfg);
                Config::saveState(Config::stateDefaults());
                destroyer_log('setup', 'Ersteinrichtung abgeschlossen.');
                redirect_with('ok', 'Einrichtung abgeschlossen. Bitte anmelden.');
            }
        }
    }
    $csrf = Auth::csrfToken();
    $tzList = timezone_identifiers_list();
    ?>
    <!doctype html>
    <html lang="de">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Einrichtung — 007DataDestroyer</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= e(APP_VERSION) ?>">
    </head>
    <body class="page-admin">
    <main class="admin-narrow">
        <p class="brand">007<span>DataDestroyer</span></p>
        <h1>Ersteinrichtung</h1>
        <p class="muted">Lege das Admin-Passwort und die öffentliche Recovery-PIN fest.</p>

        <?php if ($error !== ''): ?>
            <p class="flash flash-err" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" class="stack" autocomplete="off">
            <input type="hidden" name="action" value="setup">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

            <label for="password">Admin-Passwort (min. 8 Zeichen)</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required>

            <label for="password2">Admin-Passwort wiederholen</label>
            <input type="password" id="password2" name="password2" autocomplete="new-password" required>

            <label for="pin">Recovery-PIN (6–10 Ziffern)</label>
            <input type="password" id="pin" name="pin" inputmode="numeric" pattern="\d{6,10}" autocomplete="new-password" required>

            <label for="pin2">Recovery-PIN wiederholen</label>
            <input type="password" id="pin2" name="pin2" inputmode="numeric" pattern="\d{6,10}" autocomplete="new-password" required>

            <label for="timezone">Zeitzone</label>
            <select id="timezone" name="timezone">
                <?php foreach ($tzList as $tz): ?>
                    <option value="<?= e($tz) ?>" <?= $tz === 'Europe/Berlin' ? 'selected' : '' ?>><?= e($tz) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary">Einrichten</button>
        </form>
    </main>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================================
 * 2) AB HIER: LOGIN ERFORDERLICH
 * ========================================================================= */
Auth::requireLogin();

$cfg   = Config::load();
$sched = new Scheduler($cfg);

/* ----- POST-Aktionen ------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        redirect_with('err', 'Sicherheits-Token abgelaufen. Bitte erneut versuchen.');
    }

    switch ($action) {
        case 'save_settings':
            $mode = (string) ($_POST['mode'] ?? 'daily');
            if (!in_array($mode, Config::MODES, true)) {
                redirect_with('err', 'Ungültiger Modus.');
            }
            $start = trim((string) ($_POST['start'] ?? '00:00'));
            $end   = trim((string) ($_POST['end'] ?? '00:00'));
            if (!preg_match('/^\d{1,2}:\d{2}$/', $start) || !preg_match('/^\d{1,2}:\d{2}$/', $end)) {
                redirect_with('err', 'Zeitangaben müssen im Format HH:MM sein.');
            }

            $cfg['mode'] = $mode;
            $cfg['window'] = ['start' => $start, 'end' => $end];

            $cfg['date'] = null;
            $cfg['weekday'] = null;
            if ($mode === 'once') {
                $date = (string) ($_POST['date'] ?? '');
                $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
                if (!$d || $d->format('Y-m-d') !== $date) {
                    redirect_with('err', 'Ungültiges Datum.');
                }
                $cfg['date'] = $date;
            } elseif ($mode === 'weekly') {
                $wd = (int) ($_POST['weekday'] ?? 1);
                if ($wd < 1 || $wd > 7) {
                    redirect_with('err', 'Ungültiger Wochentag.');
                }
                $cfg['weekday'] = $wd;
            }

            $dataDir = trim((string) ($_POST['dataDir'] ?? 'data'));
            if ($dataDir === '' || strpos($dataDir, '..') !== false || $dataDir[0] === '/') {
                redirect_with('err', 'Ungültiges Datenverzeichnis.');
            }
            $cfg['dataDir'] = $dataDir;

            $email = trim((string) ($_POST['notifyEmail'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                redirect_with('err', 'Ungültige E-Mail-Adresse.');
            }
            $cfg['notifyEmail'] = $email !== '' ? $email : null;

            $cfg['disableAfterDelete'] = !empty($_POST['disableAfterDelete']);
            $cfg['secureWipe']         = !empty($_POST['secureWipe']);

            Config::save($cfg);

            // Hinweis, falls das Datenverzeichnis (noch) nicht auflösbar ist.
            if (Destroyer::resolveDataDir($cfg) === null) {
                redirect_with('err', 'Einstellungen gespeichert — ACHTUNG: Datenverzeichnis „' . e($dataDir) . '" existiert noch nicht oder ist ungültig. Bitte anlegen.');
            }
            redirect_with('ok', 'Einstellungen gespeichert.');
            break;

        case 'arm':
            if (Destroyer::resolveDataDir($cfg) === null) {
                redirect_with('err', 'Scharfschalten nicht möglich: Datenverzeichnis ungültig/fehlt.');
            }
            $cfg['armed'] = true;
            Config::save($cfg);
            $st = Config::loadState();
            $st['armedAt'] = $sched->now()->format('c');
            $st['lastCheck'] = $sched->now()->format('c');
            $st['confirmedWindows'] = [];
            Config::saveState($st);
            destroyer_log('arm', 'System scharf geschaltet: ' . $sched->describe());
            redirect_with('ok', 'System ist jetzt SCHARF. ' . $sched->describe() . '.');
            break;

        case 'disarm':
            $cfg['armed'] = false;
            Config::save($cfg);
            destroyer_log('disarm', 'System entschärft.');
            redirect_with('ok', 'System entschärft — es wird nichts gelöscht.');
            break;

        case 'change_password':
            $cur  = (string) ($_POST['current'] ?? '');
            $new  = (string) ($_POST['new'] ?? '');
            $new2 = (string) ($_POST['new2'] ?? '');
            if (!password_verify($cur, (string) $cfg['adminPasswordHash'])) {
                redirect_with('err', 'Aktuelles Passwort falsch.');
            }
            if (strlen($new) < 8 || $new !== $new2) {
                redirect_with('err', 'Neues Passwort ungültig (min. 8 Zeichen, muss übereinstimmen).');
            }
            $cfg['adminPasswordHash'] = password_hash($new, PASSWORD_DEFAULT);
            Config::save($cfg);
            destroyer_log('config', 'Admin-Passwort geändert.');
            redirect_with('ok', 'Admin-Passwort geändert.');
            break;

        case 'change_pin':
            $cur  = (string) ($_POST['current'] ?? '');
            $pin  = (string) ($_POST['pin'] ?? '');
            $pin2 = (string) ($_POST['pin2'] ?? '');
            if (!password_verify($cur, (string) $cfg['adminPasswordHash'])) {
                redirect_with('err', 'Zur PIN-Änderung bitte das Admin-Passwort bestätigen — es war falsch.');
            }
            if (!preg_match('/^\d{6,10}$/', $pin) || $pin !== $pin2) {
                redirect_with('err', 'Neue PIN ungültig (6–10 Ziffern, muss übereinstimmen).');
            }
            $cfg['recoveryPinHash'] = password_hash($pin, PASSWORD_DEFAULT);
            Config::save($cfg);
            destroyer_log('config', 'Recovery-PIN geändert.');
            redirect_with('ok', 'Recovery-PIN geändert.');
            break;

        default:
            redirect_with('err', 'Unbekannte Aktion.');
    }
}

/* ----- Lazy-Check (gedrosselt) -------------------------------------------- */
$state = Config::loadState();
$lastCheck = $state['lastCheck'] ?? null;
$runLazy = true;
if ($lastCheck) {
    try {
        $runLazy = (time() - (new DateTimeImmutable($lastCheck))->getTimestamp()) > 20;
    } catch (Throwable $ex) {
        $runLazy = true;
    }
}
if ($runLazy) {
    Watcher::run();
    $cfg = Config::load();
}

/* ----- Anzeige-Daten ------------------------------------------------------ */
$state   = Config::loadState();
$now     = $sched->now();
$armed   = !empty($cfg['armed']);
$current = $armed ? $sched->currentWindow($now) : null;
$next    = $armed ? $sched->nextWindow($now) : null;
$stats   = Destroyer::statsCached($cfg);
$csrf    = Auth::csrfToken();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// URLs für Cron und Recovery zusammenbauen.
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'))), '/');
$cronUrl   = $scheme . '://' . $host . $base . '/cron.php?token=' . rawurlencode((string) $cfg['cronToken']);
$recentUrl = $scheme . '://' . $host . $base . '/recover.php';

function human_bytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $v = (float) $b;
    while ($v >= 1024 && $i < 4) { $v /= 1024; $i++; }
    return ($i === 0 ? (string) $b : number_format($v, 1, ',', '.')) . ' ' . $u[$i];
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Backend — 007DataDestroyer</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="page-admin">
<header class="admin-top">
    <p class="brand">007<span>DataDestroyer</span></p>
    <a class="btn btn-ghost" href="logout.php">Abmelden</a>
</header>

<main class="admin-main">
    <h1 class="sr-only">007DataDestroyer — Backend</h1>

    <?php if ($flash): ?>
        <p class="flash <?= $flash['type'] === 'ok' ? 'flash-ok' : 'flash-err' ?>" role="<?= $flash['type'] === 'ok' ? 'status' : 'alert' ?>"><?= e($flash['msg']) ?></p>
    <?php endif; ?>

    <!-- Status -->
    <section class="card <?= $armed ? 'card-armed' : 'card-idle' ?>">
        <h2>Status</h2>
        <p class="big-status">
            <?php if ($armed): ?>
                <span class="dot dot-open"></span> SCHARF
            <?php else: ?>
                <span class="dot dot-idle"></span> INAKTIV
            <?php endif; ?>
        </p>
        <ul class="kv">
            <li><span>Zeitplan</span><strong><?= e($sched->describe()) ?></strong></li>
            <li><span>Datenverzeichnis</span><strong><?= e($cfg['dataDir']) ?> — <?= (int) $stats['files'] ?> Datei(en), <?= e(human_bytes((int) $stats['bytes'])) ?></strong></li>
            <li><span>Aktuelles Fenster</span><strong><?= $current ? 'OFFEN bis ' . e($current['end']->format('d.m.Y H:i')) : '—' ?></strong></li>
            <li><span>Nächstes Fenster</span><strong><?= $next ? e($next['start']->format('d.m.Y H:i')) . ' – ' . e($next['end']->format('H:i')) : '—' ?></strong></li>
            <li><span>Letzte Bestätigung</span><strong><?= $state['lastConfirm'] ? e(date('d.m.Y H:i', strtotime($state['lastConfirm']))) : '—' ?></strong></li>
            <li><span>Letzte Löschung</span><strong><?= $state['lastDeletion'] ? e(date('d.m.Y H:i', strtotime($state['lastDeletion']))) : '—' ?></strong></li>
        </ul>

        <form method="post" class="inline-form" onsubmit="return confirm('<?= $armed ? 'System wirklich entschärfen?' : 'System scharf schalten? Ab jetzt wird bei verpasstem Fenster gelöscht!' ?>');">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <?php if ($armed): ?>
                <input type="hidden" name="action" value="disarm">
                <button type="submit" class="btn btn-ghost">Entschärfen</button>
            <?php else: ?>
                <input type="hidden" name="action" value="arm">
                <button type="submit" class="btn btn-danger">Scharf schalten</button>
            <?php endif; ?>
        </form>
    </section>

    <!-- Einstellungen -->
    <section class="card">
        <h2>Einstellungen</h2>
        <form method="post" class="stack" id="settingsForm">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_settings">

            <label for="mode">Modus</label>
            <select id="mode" name="mode">
                <?php
                $modeLabels = ['once' => 'Einmalig', 'hourly' => 'Stündlich', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich'];
                foreach ($modeLabels as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= $cfg['mode'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>

            <div class="field-row<?= $cfg['mode'] === 'once' ? '' : ' is-hidden' ?>" id="row-date">
                <label for="date">Datum (nur „Einmalig")</label>
                <input type="date" id="date" name="date" value="<?= e($cfg['date'] ?? '') ?>">
            </div>

            <div class="field-row<?= $cfg['mode'] === 'weekly' ? '' : ' is-hidden' ?>" id="row-weekday">
                <label for="weekday">Wochentag (nur „Wöchentlich")</label>
                <select id="weekday" name="weekday">
                    <?php
                    $days = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
                    foreach ($days as $n => $lbl): ?>
                        <option value="<?= $n ?>" <?= (int) ($cfg['weekday'] ?? 1) === $n ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid-2">
                <div>
                    <label for="start">Fenster-Start (HH:MM)</label>
                    <input type="time" id="start" name="start" value="<?= e($cfg['window']['start'] ?? '00:07') ?>" required>
                </div>
                <div>
                    <label for="end">Fenster-Ende (HH:MM)</label>
                    <input type="time" id="end" name="end" value="<?= e($cfg['window']['end'] ?? '00:08') ?>" required>
                </div>
            </div>
            <p class="hint<?= $cfg['mode'] === 'hourly' ? '' : ' is-hidden' ?>" id="hourlyHint">Im Modus „Stündlich" zählt nur die Minute (MM) von Start/Ende — jede Stunde.</p>

            <label for="dataDir">Datenverzeichnis (relativ zum Web-Root)</label>
            <input type="text" id="dataDir" name="dataDir" value="<?= e($cfg['dataDir']) ?>" required>

            <label for="notifyEmail">E-Mail-Benachrichtigung (optional)</label>
            <input type="email" id="notifyEmail" name="notifyEmail" value="<?= e($cfg['notifyEmail'] ?? '') ?>" placeholder="leer = aus">

            <label class="check"><input type="checkbox" name="secureWipe" <?= !empty($cfg['secureWipe']) ? 'checked' : '' ?>> Dateien vor dem Löschen mit Zufallsdaten überschreiben</label>
            <label class="check"><input type="checkbox" name="disableAfterDelete" <?= !empty($cfg['disableAfterDelete']) ? 'checked' : '' ?>> Nach einer Löschung automatisch deaktivieren</label>

            <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
        </form>
    </section>

    <!-- Cron & Recovery -->
    <section class="card">
        <h2>Cron & Zugänge</h2>
        <p class="muted">Richte bei deinem Hoster einen Cronjob ein, der diese URL regelmäßig (z. B. minütlich) aufruft:</p>
        <p><code class="copyable"><?= e($cronUrl) ?></code></p>
        <p class="muted">Alternativ per CLI: <code>php <?= e(str_replace('\\', '/', PUBLIC_DIR)) ?>/cron.php</code></p>
        <p class="muted">Öffentliche Recovery-Seite (grüner Knopf):</p>
        <p><a href="<?= e($recentUrl) ?>" target="_blank" rel="noopener" aria-label="Recovery-Seite öffnen (neuer Tab)"><?= e($recentUrl) ?></a> <span class="muted">(neuer Tab)</span></p>
    </section>

    <!-- Passwort / PIN -->
    <section class="card">
        <h2>Passwort & PIN ändern</h2>
        <div class="grid-2">
            <form method="post" class="stack" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="change_password">
                <h3>Admin-Passwort</h3>
                <label>Aktuelles Passwort<input type="password" name="current" autocomplete="current-password" required></label>
                <label>Neues Passwort<input type="password" name="new" autocomplete="new-password" required></label>
                <label>Neues Passwort wiederholen<input type="password" name="new2" autocomplete="new-password" required></label>
                <button type="submit" class="btn btn-ghost">Passwort ändern</button>
            </form>
            <form method="post" class="stack" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="change_pin">
                <h3>Recovery-PIN</h3>
                <label>Admin-Passwort (Bestätigung)<input type="password" name="current" autocomplete="current-password" required></label>
                <label>Neue PIN (6–10 Ziffern)<input type="password" name="pin" pattern="\d{6,10}" inputmode="numeric" autocomplete="new-password" required></label>
                <label>Neue PIN wiederholen<input type="password" name="pin2" pattern="\d{6,10}" inputmode="numeric" autocomplete="new-password" required></label>
                <button type="submit" class="btn btn-ghost">PIN ändern</button>
            </form>
        </div>
    </section>

    <!-- Protokoll -->
    <section class="card">
        <h2>Protokoll</h2>
        <?php $log = array_reverse($state['log'] ?? []); ?>
        <?php if (!$log): ?>
            <p class="muted">Noch keine Ereignisse.</p>
        <?php else: ?>
            <table class="log-table">
                <thead><tr><th scope="col">Zeit</th><th scope="col">Typ</th><th scope="col">Ereignis</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($log, 0, 40) as $entry): ?>
                    <tr>
                        <td><?= e(date('d.m.Y H:i:s', strtotime($entry['ts'] ?? 'now'))) ?></td>
                        <td><span class="tag tag-<?= e($entry['type'] ?? 'info') ?>"><?= e($entry['type'] ?? 'info') ?></span></td>
                        <td><?= e($entry['msg'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

</main>
<script src="../assets/js/admin.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
