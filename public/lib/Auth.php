<?php
/**
 * 007DataDestroyer — Auth
 *
 * Session-basierte Anmeldung fürs Backend, CSRF-Schutz, Session-Timeout und ein
 * wiederverwendbares Rate-Limit pro Kontext+IP (gegen Brute-Force bei Admin-Login
 * und öffentlichem Recovery-PIN).
 */

declare(strict_types=1);

class Auth
{
    private const MAX_FAILS    = 5;     // Fehlversuche je Kontext+IP
    private const LOCK_SECONDS = 900;   // 15 Minuten Sperrfenster
    private const IDLE_SECONDS = 3600;  // 60 Minuten ohne Aktivität → Logout
    private const ABS_SECONDS  = 43200; // 12 Stunden absolute Sitzungsdauer

    /** Startet die Session mit sicheren Cookie-Parametern. */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // HTTPS nur aus der direkten Server-Variable ableiten (kein spoofbarer Header).
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('DD007SESS');
        session_start();
    }

    public static function isLoggedIn(): bool
    {
        self::startSession();
        if (empty($_SESSION['dd007_auth'])) {
            return false;
        }
        $now   = time();
        $since = (int) ($_SESSION['dd007_since'] ?? 0);
        $seen  = (int) ($_SESSION['dd007_seen'] ?? 0);

        // Absolutes und Idle-Timeout erzwingen.
        if (($since > 0 && $now - $since > self::ABS_SECONDS)
            || ($seen > 0 && $now - $seen > self::IDLE_SECONDS)) {
            self::logout();
            return false;
        }
        $_SESSION['dd007_seen'] = $now; // letzte Aktivität aktualisieren
        return true;
    }

    /** Erzwingt Login; leitet sonst zur Login-Seite weiter. */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }

    /** Prüft Passwort, setzt bei Erfolg die Session. Beachtet Rate-Limit. */
    public static function attemptLogin(string $password): bool
    {
        self::startSession();

        if (self::tooManyAttempts('login')) {
            return false;
        }

        $cfg = Config::load();
        $hash = $cfg['adminPasswordHash'] ?? '';

        if ($hash !== '' && password_verify($password, $hash)) {
            self::clearAttempts('login');
            session_regenerate_id(true);
            $_SESSION['dd007_auth']  = true;
            $_SESSION['dd007_since'] = time();
            $_SESSION['dd007_seen']  = time();
            return true;
        }

        self::recordAttempt('login');
        return false;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ----- CSRF -------------------------------------------------------------

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['dd007_csrf'])) {
            $_SESSION['dd007_csrf'] = random_token(32);
        }
        return $_SESSION['dd007_csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::startSession();
        return !empty($_SESSION['dd007_csrf'])
            && is_string($token)
            && hash_equals($_SESSION['dd007_csrf'], $token);
    }

    // ----- Wiederverwendbares Rate-Limit (Kontext + IP) --------------------

    private static function ipHash(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        return substr(hash('sha256', (string) $ip), 0, 16);
    }

    private static function bucketKey(string $context): string
    {
        return $context . '|' . self::ipHash();
    }

    /** Aktuelle, noch gültige Fehlversuche für Kontext+IP. */
    private static function recentAttempts(string $context): array
    {
        $st = Config::loadState();
        $buckets = is_array($st['rateLimits'] ?? null) ? $st['rateLimits'] : [];
        $key = self::bucketKey($context);
        $list = is_array($buckets[$key] ?? null) ? $buckets[$key] : [];
        $cutoff = time() - self::LOCK_SECONDS;
        return array_values(array_filter($list, static function ($t) use ($cutoff) {
            return (int) $t >= $cutoff;
        }));
    }

    public static function tooManyAttempts(string $context, int $max = self::MAX_FAILS): bool
    {
        return count(self::recentAttempts($context)) >= $max;
    }

    /** Verbleibende Sperrsekunden (0 = nicht gesperrt). */
    public static function lockRemaining(string $context, int $max = self::MAX_FAILS): int
    {
        $list = self::recentAttempts($context);
        if (count($list) < $max) {
            return 0;
        }
        $oldest = min($list);
        return max(0, ($oldest + self::LOCK_SECONDS) - time());
    }

    public static function recordAttempt(string $context): void
    {
        $st = Config::loadState();
        $buckets = is_array($st['rateLimits'] ?? null) ? $st['rateLimits'] : [];
        $key = self::bucketKey($context);
        $list = self::recentAttempts($context);
        $list[] = time();
        $buckets[$key] = $list;

        // Alte/leere Buckets aufräumen, damit state.json nicht wächst.
        $cutoff = time() - self::LOCK_SECONDS;
        foreach ($buckets as $k => $v) {
            $v = array_values(array_filter((array) $v, static function ($t) use ($cutoff) {
                return (int) $t >= $cutoff;
            }));
            if ($v) {
                $buckets[$k] = $v;
            } else {
                unset($buckets[$k]);
            }
        }

        $st['rateLimits'] = $buckets;
        Config::saveState($st);
    }

    public static function clearAttempts(string $context): void
    {
        $st = Config::loadState();
        $buckets = is_array($st['rateLimits'] ?? null) ? $st['rateLimits'] : [];
        unset($buckets[self::bucketKey($context)]);
        $st['rateLimits'] = $buckets;
        Config::saveState($st);
    }
}
