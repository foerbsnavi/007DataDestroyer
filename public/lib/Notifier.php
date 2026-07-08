<?php
/**
 * 007DataDestroyer — Notifier
 *
 * Optionale E-Mail-Benachrichtigung über PHP mail(). Wird nur versendet, wenn
 * in der Konfiguration eine gültige Empfängeradresse hinterlegt ist.
 */

declare(strict_types=1);

class Notifier
{
    /**
     * Versendet eine Benachrichtigung, sofern notifyEmail gesetzt und gültig ist.
     */
    public static function send(array $cfg, string $subject, string $body): bool
    {
        $to = $cfg['notifyEmail'] ?? null;
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Absender aus dem Host ableiten (viele Hoster verlangen eine Domain-Adresse).
        $host = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) $host);
        if ($host === '' ) {
            $host = 'localhost';
        }
        $from = 'no-reply@' . $host;

        // Header sauber halten (keine Injection über Betreff/Empfänger möglich, da beide validiert).
        $headers = [
            'From: 007DataDestroyer <' . $from . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: 007DataDestroyer',
        ];

        $safeSubject = str_replace(["\r", "\n"], ' ', $subject);

        return @mail($to, '[007DataDestroyer] ' . $safeSubject, $body, implode("\r\n", $headers));
    }
}
