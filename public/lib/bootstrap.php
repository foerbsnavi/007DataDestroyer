<?php
/**
 * 007DataDestroyer — Bootstrap
 *
 * Zentrale Initialisierung: Pfade definieren, Klassen laden, Zeitzone setzen.
 * Wird von jedem Einstiegspunkt (recover.php, cron.php, admin/*) eingebunden.
 */

declare(strict_types=1);

// Fehler nicht an den Browser ausgeben (Sicherheit) — nur ins Log.
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------------------------------------------------------------------
// Pfade (public/ ist das Webroot)
// ---------------------------------------------------------------------------
define('APP_VERSION', '1.0.0');                   // für Asset-Cache-Busting (?v=)
define('PUBLIC_DIR', dirname(__DIR__));          // .../public
define('LIB_DIR', __DIR__);                       // .../public/lib
define('STORAGE_DIR', PUBLIC_DIR . '/storage');   // .../public/storage
define('CONFIG_FILE', STORAGE_DIR . '/config.json');
define('STATE_FILE', STORAGE_DIR . '/state.json');
define('LOG_FILE', STORAGE_DIR . '/destroyer.log');

// PHP-Fehler in eine gesperrte Datei innerhalb storage/ schreiben (nicht in ein
// evtl. web-erreichbares Standard-Log). storage/ ist per .htaccess gesperrt.
if (!is_dir(STORAGE_DIR)) {
    @mkdir(STORAGE_DIR, 0775, true);
}
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_DIR . '/php_error.log');

// ---------------------------------------------------------------------------
// Klassen laden
// ---------------------------------------------------------------------------
require_once LIB_DIR . '/helpers.php';
require_once LIB_DIR . '/Config.php';
require_once LIB_DIR . '/Scheduler.php';
require_once LIB_DIR . '/Destroyer.php';
require_once LIB_DIR . '/Notifier.php';
require_once LIB_DIR . '/Watcher.php';
require_once LIB_DIR . '/Auth.php';

// ---------------------------------------------------------------------------
// Zeitzone aus der Konfiguration (Standard: Europe/Berlin)
// ---------------------------------------------------------------------------
$__tz = 'Europe/Berlin';
if (Config::exists()) {
    $__cfg = Config::load();
    if (!empty($__cfg['timezone']) && in_array($__cfg['timezone'], timezone_identifiers_list(), true)) {
        $__tz = $__cfg['timezone'];
    }
}
date_default_timezone_set($__tz);
unset($__tz, $__cfg);
