<?php
/**
 * 007DataDestroyer — Cron-Endpoint (Wächter)
 *
 * Von einem Cronjob regelmäßig aufzurufen. Wertet abgelaufene Zeitfenster aus
 * und löscht das Datenverzeichnis, wenn der Recovery-Knopf nicht gedrückt wurde.
 *
 * Aufruf per Cron (empfohlen so oft wie möglich, z. B. minütlich):
 *   CLI:  php /pfad/zu/public/cron.php
 *   HTTP: wget -qO- "https://deine-domain/cron.php?token=DEIN_TOKEN"
 *
 * Der HTTP-Aufruf verlangt zwingend das geheime cronToken. Der CLI-Aufruf ist
 * ohne Token erlaubt (nur der Server selbst kann ihn auslösen).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

// --- Zugriffsschutz --------------------------------------------------------
if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');

    $cfg = Config::exists() ? Config::load() : [];
    $expected = $cfg['cronToken'] ?? '';
    $given = (string) ($_GET['token'] ?? '');

    if ($expected === '' || !hash_equals((string) $expected, $given)) {
        http_response_code(403);
        echo "403 Forbidden\n";
        exit;
    }
}

// --- Prüflauf --------------------------------------------------------------
$result = Watcher::run();

$line = sprintf(
    "[%s] ran=%s deleted=%s missed=%s :: %s\n",
    date('c'),
    $result['ran'] ? '1' : '0',
    $result['deleted'] ? '1' : '0',
    $result['missed'] ? implode(',', $result['missed']) : '-',
    $result['message']
);

echo $line;
