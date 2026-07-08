<?php
/**
 * 007DataDestroyer — Watcher
 *
 * Das Herzstück der Löschentscheidung. Prüft, ob seit dem letzten Lauf ein
 * Zeitfenster vollständig abgelaufen ist, ohne dass der Recovery-Knopf gedrückt
 * wurde. Falls ja: Datenverzeichnis löschen.
 *
 * Wird sowohl vom Cronjob (cron.php) als auch als "Lazy-Check" beim Seitenaufruf
 * verwendet. Mehrfachaufrufe sind unschädlich, da bereits ausgewertete Fenster
 * über lastCheck nicht erneut betrachtet werden.
 */

declare(strict_types=1);

class Watcher
{
    /**
     * Führt einen Prüflauf durch.
     *
     * @return array{ran:bool, deleted:bool, missed:array<int,string>, message:string}
     */
    public static function run(): array
    {
        $out = ['ran' => false, 'deleted' => false, 'missed' => [], 'message' => ''];

        if (Config::needsSetup()) {
            $out['message'] = 'Setup unvollständig';
            return $out;
        }

        $cfg = Config::load();
        if (empty($cfg['armed'])) {
            $out['message'] = 'System nicht scharf';
            return $out;
        }

        $state = Config::loadState();
        $sched = new Scheduler($cfg);
        $now   = $sched->now();

        // Referenzpunkt: letzter Lauf, sonst Scharfstell-Zeitpunkt, sonst jetzt.
        $sinceStr = $state['lastCheck'] ?? ($state['armedAt'] ?? $cfg['createdAt']);
        try {
            $since = $sinceStr
                ? new DateTimeImmutable($sinceStr, $now->getTimezone())
                : $now;
        } catch (Throwable $ex) {
            $since = $now;
        }

        $out['ran'] = true;

        // Abgelaufene Fenster seit dem letzten Lauf ermitteln.
        $elapsed = $sched->elapsedWindowsSince($since, $now);
        $confirmed = is_array($state['confirmedWindows'] ?? null) ? $state['confirmedWindows'] : [];

        $missed = [];
        foreach ($elapsed as $w) {
            if (!in_array($w['id'], $confirmed, true)) {
                $missed[] = $w['id'];
            }
        }

        if ($missed) {
            $out['missed'] = $missed;
            $result = Destroyer::purge($cfg);
            $out['deleted'] = $result['ok'];

            if ($result['ok']) {
                $msg = sprintf(
                    'Fenster verpasst (%s) — %d Datei(en) / %s gelöscht.',
                    implode(', ', $missed),
                    $result['files'],
                    self::humanBytes($result['bytes'])
                );
                // Erst ALLE Log-Einträge schreiben (destroyer_log mutiert state.json direkt),
                // dann state.json einmal frisch laden und die Felder ergänzen — sonst würden
                // die zuletzt geschriebenen Log-Einträge wieder überschrieben.
                destroyer_log('delete', $msg);
                $out['message'] = $msg;

                if (!empty($cfg['disableAfterDelete'])) {
                    $cfg['armed'] = false;
                    Config::save($cfg);
                    destroyer_log('disarm', 'System nach Löschung automatisch deaktiviert.');
                }

                $state = Config::loadState();
                $state['lastDeletion'] = $now->format('c');
                $state['dataStats'] = null; // Cache verwerfen (Verzeichnis jetzt leer)

                Notifier::send(
                    $cfg,
                    'Daten gelöscht',
                    "Der Recovery-Knopf wurde im Zeitfenster nicht betätigt.\n\n" . $msg
                        . "\n\nZeitpunkt: " . $now->format('d.m.Y H:i:s')
                );
            } else {
                $err = 'Löschung fehlgeschlagen: ' . ($result['error'] ?? 'unbekannt');
                destroyer_log('error', $err);
                $out['message'] = $err;
                $state = Config::loadState(); // enthält jetzt den Fehler-Logeintrag
                // Admin informieren — sonst bliebe ein Fehlversuch unbemerkt.
                Notifier::send(
                    $cfg,
                    'Löschung FEHLGESCHLAGEN',
                    "Ein verpasstes Fenster (" . implode(', ', $missed) . ") wurde erkannt, "
                        . "aber die Löschung schlug fehl:\n\n" . $err
                        . "\n\nBitte manuell prüfen. Zeitpunkt: " . $now->format('d.m.Y H:i:s')
                );
            }
        } else {
            $out['message'] = $elapsed
                ? 'Alle abgelaufenen Fenster bestätigt.'
                : 'Kein abgelaufenes Fenster.';
            // Kein Schreibvorgang seit dem Laden — $state von oben weiterverwenden.
        }

        // lastCheck immer fortschreiben und confirmedWindows beschneiden.
        $state['lastCheck'] = $now->format('c');
        if (count($confirmed) > 200) {
            $state['confirmedWindows'] = array_slice($confirmed, -200);
        }
        Config::saveState($state);

        return $out;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $val = (float) $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($val, 1, ',', '.')) . ' ' . $units[$i];
    }
}
