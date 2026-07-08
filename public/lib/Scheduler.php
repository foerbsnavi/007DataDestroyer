<?php
/**
 * 007DataDestroyer — Scheduler
 *
 * Berechnet die Zeitfenster aus der Konfiguration. Ein Fenster ist der Zeitraum,
 * in dem der grüne Recovery-Knopf gedrückt werden MUSS. Wird er in einem
 * abgelaufenen Fenster nicht gedrückt, löscht der Wächter das Datenverzeichnis.
 *
 * Fenster-Semantik je Modus (window = {start, end} als "HH:MM"):
 *   once    – genau ein Fenster am Datum "date"
 *   hourly  – jede Stunde; es zählt nur die Minute (MM) von start/end
 *   daily   – jeden Tag zur Uhrzeit start..end
 *   weekly  – am Wochentag "weekday" (ISO 1=Mo..7=So) zur Uhrzeit start..end
 *
 * Läuft end <= start, endet das Fenster in der nächsten Einheit (Tag/Stunde) —
 * so werden über Mitternacht/Stundengrenze reichende Fenster unterstützt.
 */

declare(strict_types=1);

class Scheduler
{
    /** @var array */
    private $cfg;
    /** @var DateTimeZone */
    private $tz;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $tzName = (!empty($cfg['timezone']) && in_array($cfg['timezone'], timezone_identifiers_list(), true))
            ? $cfg['timezone'] : 'Europe/Berlin';
        $this->tz = new DateTimeZone($tzName);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->tz);
    }

    /** Eindeutige ID eines Fensters aus seiner Startzeit. */
    public static function windowId(DateTimeInterface $start): string
    {
        return $start->format('Y-m-d\TH:i');
    }

    /**
     * Zerlegt "HH:MM" in [stunde, minute]. Fällt auf 0 zurück.
     * @return int[]
     */
    private function parseTime(string $hhmm): array
    {
        $parts = explode(':', trim($hhmm));
        $h = isset($parts[0]) ? (int) $parts[0] : 0;
        $m = isset($parts[1]) ? (int) $parts[1] : 0;
        return [max(0, min(23, $h)), max(0, min(59, $m))];
    }

    /**
     * Baut ein Fenster [start, end] aus einem Anker-Zeitpunkt und Dauer-Logik.
     * $unit bestimmt, um wieviel end verschoben wird, falls end <= start.
     */
    private function makeWindow(DateTimeImmutable $start, DateTimeImmutable $end, string $unit): array
    {
        if ($end <= $start) {
            $end = $end->modify($unit === 'hour' ? '+1 hour' : '+1 day');
        }
        return ['start' => $start, 'end' => $end, 'id' => self::windowId($start)];
    }

    /**
     * Erzeugt alle Fenster-Instanzen, deren Startzeit im Bereich [$from, $to] liegt.
     * Bereich wird vor $from leicht erweitert, damit gerade laufende Fenster erfasst werden.
     *
     * @return array<int,array{start:DateTimeImmutable,end:DateTimeImmutable,id:string}>
     */
    public function windowsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $mode = $this->cfg['mode'] ?? 'daily';
        $win  = is_array($this->cfg['window'] ?? null) ? $this->cfg['window'] : [];
        [$sh, $sm] = $this->parseTime((string) ($win['start'] ?? '00:00'));
        [$eh, $em] = $this->parseTime((string) ($win['end'] ?? '00:00'));

        // Puffer, damit ein Fenster, das vor $from begann aber nach $from endet, erfasst wird.
        $scanFrom = $from->modify('-1 day');
        $windows  = [];
        $guard    = 0;

        switch ($mode) {
            case 'once':
                if (empty($this->cfg['date'])) {
                    return [];
                }
                $day = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $this->cfg['date'] . ' 00:00',
                    $this->tz
                );
                if ($day === false) {
                    return [];
                }
                $start = $day->setTime($sh, $sm);
                $end   = $day->setTime($eh, $em);
                $w = $this->makeWindow($start, $end, 'day');
                if ($w['start'] >= $scanFrom && $w['start'] <= $to) {
                    $windows[] = $w;
                }
                break;

            case 'hourly':
                // Nur die Minute zählt; jede Stunde ein Fenster.
                $cursor = $scanFrom->setTime((int) $scanFrom->format('H'), 0);
                while ($cursor <= $to && $guard++ < 2000) {
                    $start = $cursor->setTime((int) $cursor->format('H'), $sm);
                    $end   = $cursor->setTime((int) $cursor->format('H'), $em);
                    $w = $this->makeWindow($start, $end, 'hour');
                    if ($w['start'] >= $scanFrom && $w['start'] <= $to) {
                        $windows[] = $w;
                    }
                    $cursor = $cursor->modify('+1 hour');
                }
                break;

            case 'weekly':
                $targetDow = (int) ($this->cfg['weekday'] ?? 1); // ISO 1..7
                $cursor = $scanFrom->setTime(0, 0);
                while ($cursor <= $to && $guard++ < 400) {
                    if ((int) $cursor->format('N') === $targetDow) {
                        $start = $cursor->setTime($sh, $sm);
                        $end   = $cursor->setTime($eh, $em);
                        $w = $this->makeWindow($start, $end, 'day');
                        if ($w['start'] >= $scanFrom && $w['start'] <= $to) {
                            $windows[] = $w;
                        }
                    }
                    $cursor = $cursor->modify('+1 day');
                }
                break;

            case 'daily':
            default:
                $cursor = $scanFrom->setTime(0, 0);
                while ($cursor <= $to && $guard++ < 400) {
                    $start = $cursor->setTime($sh, $sm);
                    $end   = $cursor->setTime($eh, $em);
                    $w = $this->makeWindow($start, $end, 'day');
                    if ($w['start'] >= $scanFrom && $w['start'] <= $to) {
                        $windows[] = $w;
                    }
                    $cursor = $cursor->modify('+1 day');
                }
                break;
        }

        usort($windows, static function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });
        return $windows;
    }

    /**
     * Gibt das gerade aktive Fenster zurück (start <= now <= end) oder null.
     */
    public function currentWindow(?DateTimeImmutable $now = null): ?array
    {
        $now = $now ?? $this->now();
        $windows = $this->windowsBetween($now->modify('-1 day'), $now);
        foreach ($windows as $w) {
            // Ende exklusiv: exakt auf der Endsekunde gilt das Fenster als abgelaufen,
            // nicht mehr als aktiv — verhindert Überschneidung mit elapsedWindowsSince().
            if ($now >= $w['start'] && $now < $w['end']) {
                return $w;
            }
        }
        return null;
    }

    /**
     * Fenster, die seit $since vollständig abgelaufen sind (end > $since && end <= $now).
     * Diese wertet der Wächter auf fehlende Bestätigung aus.
     *
     * @return array<int,array{start:DateTimeImmutable,end:DateTimeImmutable,id:string}>
     */
    public function elapsedWindowsSince(DateTimeImmutable $since, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? $this->now();
        $result = [];
        foreach ($this->windowsBetween($since->modify('-1 day'), $now) as $w) {
            if ($w['end'] > $since && $w['end'] <= $now) {
                $result[] = $w;
            }
        }
        return $result;
    }

    /**
     * Nächstes bevorstehendes Fenster (start > now). null wenn keines mehr kommt (once, vergangen).
     */
    public function nextWindow(?DateTimeImmutable $now = null): ?array
    {
        $now = $now ?? $this->now();
        // Horizont je Modus großzügig wählen.
        $horizonMap = ['hourly' => '+3 hours', 'daily' => '+2 days', 'weekly' => '+15 days', 'once' => '+2 days'];
        $horizon = $horizonMap[$this->cfg['mode'] ?? 'daily'] ?? '+2 days';
        foreach ($this->windowsBetween($now, $now->modify($horizon)) as $w) {
            if ($w['start'] > $now) {
                return $w;
            }
        }
        return null;
    }

    /** Menschlich lesbare Beschreibung des eingestellten Zeitplans. */
    public function describe(): string
    {
        $s = $this->cfg['window']['start'] ?? '00:00';
        $e = $this->cfg['window']['end'] ?? '00:00';
        switch ($this->cfg['mode'] ?? 'daily') {
            case 'once':
                return 'Einmalig am ' . ($this->cfg['date'] ?? '?') . ' zwischen ' . $s . ' und ' . $e . ' Uhr';
            case 'hourly':
                [, $sm] = $this->parseTime($s);
                [, $em] = $this->parseTime($e);
                return sprintf('Stündlich zwischen Minute %02d und %02d', $sm, $em);
            case 'weekly':
                $days = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
                $d = $days[(int) ($this->cfg['weekday'] ?? 1)] ?? '?';
                return 'Wöchentlich am ' . $d . ' zwischen ' . $s . ' und ' . $e . ' Uhr';
            case 'daily':
            default:
                return 'Täglich zwischen ' . $s . ' und ' . $e . ' Uhr';
        }
    }
}
