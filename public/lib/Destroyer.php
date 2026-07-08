<?php
/**
 * 007DataDestroyer — Destroyer
 *
 * Löscht den Inhalt des Datenverzeichnisses restlos. Der Ordner selbst bleibt
 * bestehen, nur sein Inhalt wird entfernt. Optional werden Dateien vor dem
 * Löschen mit Zufallsdaten überschrieben (best-effort sicheres Löschen).
 *
 * SICHERHEIT: Der Zielpfad wird streng validiert — er muss innerhalb von
 * PUBLIC_DIR liegen, darf nicht PUBLIC_DIR/STORAGE_DIR/LIB_DIR selbst sein und
 * keine Symlinks nach außen enthalten. So ist Path-Traversal ausgeschlossen.
 */

declare(strict_types=1);

class Destroyer
{
    /**
     * Ermittelt den absoluten, validierten Pfad des Datenverzeichnisses.
     * Gibt null zurück, wenn der Pfad unsicher/ungültig ist.
     */
    public static function resolveDataDir(array $cfg): ?string
    {
        $rel = $cfg['dataDir'] ?? 'data';

        // Nur einfache Verzeichnisnamen relativ zu PUBLIC_DIR erlauben — kein "..", kein Slash-Ausbruch.
        $rel = str_replace('\\', '/', (string) $rel);
        if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) {
            return null;
        }

        $candidate = PUBLIC_DIR . '/' . $rel;
        $real = realpath($candidate);
        if ($real === false) {
            return null; // existiert nicht
        }

        $publicReal = realpath(PUBLIC_DIR);
        if ($publicReal === false) {
            return null;
        }

        // Muss echtes Unterverzeichnis von PUBLIC_DIR sein.
        $normReal   = rtrim(str_replace('\\', '/', $real), '/');
        $normPublic = rtrim(str_replace('\\', '/', $publicReal), '/');
        if (strpos($normReal . '/', $normPublic . '/') !== 0 || $normReal === $normPublic) {
            return null;
        }

        // Darf keines der System-/App-Verzeichnisse ODER deren Unterordner sein.
        $forbidden = [
            rtrim(str_replace('\\', '/', (string) realpath(STORAGE_DIR)), '/'),
            rtrim(str_replace('\\', '/', (string) realpath(LIB_DIR)), '/'),
            rtrim(str_replace('\\', '/', (string) realpath(PUBLIC_DIR . '/admin')), '/'),
            rtrim(str_replace('\\', '/', (string) realpath(PUBLIC_DIR . '/assets')), '/'),
        ];
        foreach ($forbidden as $f) {
            if ($f === '') {
                continue;
            }
            // Exakte Gleichheit oder Unterordner (Präfix mit "/") ist verboten.
            if ($normReal === $f || strpos($normReal . '/', $f . '/') === 0) {
                return null;
            }
        }

        if (!is_dir($real)) {
            return null;
        }
        return $real;
    }

    /**
     * Löscht den kompletten Inhalt des Datenverzeichnisses.
     *
     * @return array{ok:bool, files:int, bytes:int, error:?string}
     */
    public static function purge(array $cfg): array
    {
        $dir = self::resolveDataDir($cfg);
        if ($dir === null) {
            return ['ok' => false, 'files' => 0, 'bytes' => 0, 'error' => 'Datenverzeichnis ungültig oder nicht auffindbar'];
        }

        $secureWipe = !empty($cfg['secureWipe']);
        $files = 0;
        $bytes = 0;

        // Die Schutz-.htaccess im Wurzelverzeichnis von data/ bleibt erhalten,
        // damit der Ordner nach der Löschung nicht ungeschützt ist.
        $keepRoot = str_replace('\\', '/', $dir) . '/.htaccess';

        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                $path = $item->getPathname();

                if (str_replace('\\', '/', $path) === $keepRoot) {
                    continue; // Schutzdatei nicht löschen
                }

                // Symlinks nur entfernen (nie hindurchfolgen/überschreiben).
                if ($item->isLink()) {
                    @unlink($path);
                    continue;
                }
                if ($item->isDir()) {
                    @rmdir($path);
                    continue;
                }
                // Datei
                $size = (int) @$item->getSize();
                if ($secureWipe && $size > 0 && $size < 50 * 1024 * 1024) {
                    self::wipeFile($path, $size);
                }
                if (@unlink($path)) {
                    $files++;
                    $bytes += $size;
                }
            }
        } catch (Throwable $ex) {
            return ['ok' => false, 'files' => $files, 'bytes' => $bytes, 'error' => $ex->getMessage()];
        }

        return ['ok' => true, 'files' => $files, 'bytes' => $bytes, 'error' => null];
    }

    /**
     * Überschreibt eine Datei einmalig mit Zufallsdaten (best-effort).
     * Hinweis: Auf SSD/Shared-Hosting ist forensisch sicheres Löschen nicht garantierbar.
     */
    private static function wipeFile(string $path, int $size): void
    {
        $fh = @fopen($path, 'r+b');
        if ($fh === false) {
            return;
        }
        try {
            $written = 0;
            while ($written < $size) {
                $chunk = min(65536, $size - $written);
                fwrite($fh, random_bytes($chunk));
                $written += $chunk;
            }
            fflush($fh);
        } catch (Throwable $ex) {
            // best-effort — ignorieren
        } finally {
            fclose($fh);
        }
    }

    /**
     * Kennzahlen des Datenverzeichnisses (für das Dashboard).
     *
     * @return array{exists:bool, files:int, bytes:int}
     */
    public static function stats(array $cfg): array
    {
        $dir = self::resolveDataDir($cfg);
        if ($dir === null) {
            return ['exists' => false, 'files' => 0, 'bytes' => 0];
        }
        $files = 0;
        $bytes = 0;
        $keepRoot = str_replace('\\', '/', $dir) . '/.htaccess';
        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($items as $item) {
                if ($item->isFile() && !$item->isLink()
                    && str_replace('\\', '/', $item->getPathname()) !== $keepRoot) {
                    $files++;
                    $bytes += (int) @$item->getSize();
                }
            }
        } catch (Throwable $ex) {
            // ignorieren
        }
        return ['exists' => true, 'files' => $files, 'bytes' => $bytes];
    }

    /**
     * Wie stats(), aber mit kurzlebigem Cache im state.json, damit das Dashboard
     * nicht bei jedem Aufruf das gesamte Verzeichnis rekursiv durchläuft.
     *
     * @return array{exists:bool, files:int, bytes:int}
     */
    public static function statsCached(array $cfg, int $ttl = 30): array
    {
        $st = Config::loadState();
        $c  = $st['dataStats'] ?? null;
        if (is_array($c) && isset($c['ts']) && (time() - (int) $c['ts']) < $ttl) {
            return [
                'exists' => (bool) ($c['exists'] ?? true),
                'files'  => (int) ($c['files'] ?? 0),
                'bytes'  => (int) ($c['bytes'] ?? 0),
            ];
        }
        $s = self::stats($cfg);
        $st = Config::loadState();
        $st['dataStats'] = ['exists' => $s['exists'], 'files' => $s['files'], 'bytes' => $s['bytes'], 'ts' => time()];
        Config::saveState($st);
        return $s;
    }
}
