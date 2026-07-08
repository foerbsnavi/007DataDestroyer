<?php
/**
 * 007DataDestroyer — Konfiguration & Zustand
 *
 * Kapselt Laden/Speichern von config.json (Einstellungen) und state.json (Laufzeitzustand).
 */

declare(strict_types=1);

class Config
{
    /** Erlaubte Modi. */
    public const MODES = ['once', 'hourly', 'daily', 'weekly'];

    /** Request-Cache für die Konfiguration (config.json ändert sich nur via save()). */
    private static $cfgCache = null;

    /** Standardwerte für eine frische Konfiguration. */
    public static function defaults(): array
    {
        return [
            'mode'               => 'daily',
            'window'             => ['start' => '00:07', 'end' => '00:08'],
            'date'               => null,          // nur "once": "YYYY-MM-DD"
            'weekday'            => null,          // nur "weekly": 1 (Mo) .. 7 (So), ISO-8601
            'dataDir'            => 'data',         // relativ zu PUBLIC_DIR
            'timezone'           => 'Europe/Berlin',
            'disableAfterDelete' => false,          // Standard laut Absprache: scharf bleiben
            'recoveryPinHash'    => null,           // Pflicht (Setup erzwingt PIN)
            'notifyEmail'        => null,           // optional
            'secureWipe'        => true,           // vor dem Löschen mit Zufallsdaten überschreiben
            'cronToken'          => null,           // geheimes Token für cron.php
            'adminPasswordHash'  => null,           // bcrypt
            'armed'              => false,
            'createdAt'          => null,
        ];
    }

    public static function exists(): bool
    {
        return is_file(CONFIG_FILE);
    }

    /** Gibt true zurück, wenn der Setup-Wizard noch durchlaufen werden muss. */
    public static function needsSetup(): bool
    {
        if (!self::exists()) {
            return true;
        }
        $cfg = self::load();
        return empty($cfg['adminPasswordHash']);
    }

    public static function load(): array
    {
        if (self::$cfgCache === null) {
            $cfg = json_read(CONFIG_FILE, []);
            if (!is_array($cfg)) {
                $cfg = [];
            }
            self::$cfgCache = array_merge(self::defaults(), $cfg);
        }
        // Kopie zurückgeben — Aufrufer dürfen mutieren, ohne den Cache zu verändern.
        return self::$cfgCache;
    }

    public static function save(array $cfg): bool
    {
        // Nur bekannte Schlüssel speichern.
        $clean = array_intersect_key($cfg, self::defaults());
        $merged = array_merge(self::defaults(), $clean);
        $ok = json_write(CONFIG_FILE, $merged);
        if ($ok) {
            self::$cfgCache = $merged; // Cache konsistent halten
        }
        return $ok;
    }

    // ----- Zustand (state.json) --------------------------------------------

    public static function stateDefaults(): array
    {
        return [
            'confirmedWindows' => [],   // Liste von Fenster-IDs
            'lastConfirm'      => null,
            'lastCheck'        => null, // ISO-Zeit des letzten Wächter-Laufs
            'lastDeletion'     => null,
            'armedAt'          => null, // Referenzpunkt für den ersten Wächterlauf
            'rateLimits'       => [],   // Rate-Limit-Buckets: "kontext|ip-hash" => [timestamps]
            'dataStats'        => null, // gecachte Kennzahlen des Datenverzeichnisses (files/bytes/ts)
            'log'              => [],
        ];
    }

    public static function loadState(): array
    {
        $st = json_read(STATE_FILE, []);
        if (!is_array($st)) {
            $st = [];
        }
        return array_merge(self::stateDefaults(), $st);
    }

    public static function saveState(array $st): bool
    {
        $clean = array_intersect_key($st, self::stateDefaults());
        return json_write(STATE_FILE, array_merge(self::stateDefaults(), $clean));
    }
}
