<?php
/**
 * 007DataDestroyer — Hilfsfunktionen
 *
 * Kleine, zustandslose Werkzeuge: JSON-Datei-I/O mit Sperre, HTML-Escaping,
 * sichere Zufallswerte, Logging.
 */

declare(strict_types=1);

/**
 * Liest eine JSON-Datei mit gemeinsamer Sperre. Gibt bei Fehler $default zurück.
 *
 * @return mixed
 */
function json_read(string $file, $default = null)
{
    if (!is_file($file)) {
        return $default;
    }
    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        return $default;
    }
    try {
        if (!flock($fh, LOCK_SH)) {
            return $default;
        }
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : $default;
}

/**
 * Schreibt Daten atomar als JSON (temporäre Datei + rename) mit exklusiver Sperre.
 */
function json_write(string $file, $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        return false;
    }
    // Atomar schreiben: erst in temporäre Datei, dann umbenennen.
    $tmp = $file . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'wb');
    if ($fh === false) {
        return false;
    }
    $ok = false;
    try {
        if (flock($fh, LOCK_EX)) {
            fwrite($fh, $json);
            fflush($fh);
            flock($fh, LOCK_UN);
            $ok = true;
        }
    } finally {
        fclose($fh);
    }
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0640);
    return true;
}

/**
 * HTML-Escaping für die Ausgabe (XSS-Schutz).
 */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Kryptografisch sicheres Zufalls-Token (hex).
 */
function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Hängt einen Eintrag an das Protokoll (Datei-Log + state.log, gekürzt).
 */
function destroyer_log(string $type, string $msg): void
{
    $ts = date('c');
    $line = sprintf("[%s] %-8s %s\n", $ts, strtoupper($type), $msg);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    @chmod(LOG_FILE, 0640);

    // Zusätzlich in state.json für die Dashboard-Anzeige (letzte 100 Einträge).
    $state = json_read(STATE_FILE, []);
    if (!is_array($state)) {
        $state = [];
    }
    if (!isset($state['log']) || !is_array($state['log'])) {
        $state['log'] = [];
    }
    $state['log'][] = ['ts' => $ts, 'type' => $type, 'msg' => $msg];
    if (count($state['log']) > 100) {
        $state['log'] = array_slice($state['log'], -100);
    }
    json_write(STATE_FILE, $state);
}

/**
 * Menschlich lesbare Zeitspanne (z. B. "2 Std 5 Min").
 */
function human_duration(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    $parts = [];
    if ($d > 0) { $parts[] = $d . ' Tg'; }
    if ($h > 0) { $parts[] = $h . ' Std'; }
    if ($m > 0) { $parts[] = $m . ' Min'; }
    if ($s > 0 && $d === 0 && $h === 0) { $parts[] = $s . ' Sek'; }
    return $parts ? implode(' ', $parts) : '0 Sek';
}
