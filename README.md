<div align="center">

# 🟢 007DataDestroyer

### Ein „Dead Man's Switch" für ein Web-Verzeichnis

Der Inhalt eines Datenordners wird **automatisch und restlos gelöscht** —
_außer_ der grüne Recovery-Knopf wird rechtzeitig gedrückt.

![Status](https://img.shields.io/badge/status-stabil-12d67a?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892bf?style=flat-square)
![Kein Framework](https://img.shields.io/badge/dependencies-keine-06536c?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-ea6b17?style=flat-square)

</div>

---

```
        ┌─────────────────────┐        drückt PIN + grünen Knopf
        │   recover.php  🟢   │  ◀───────────────────────────────  du
        │  (öffentlich, PIN)  │        im Zeitfenster
        └──────────┬──────────┘
                   │ bestätigt Fenster
                   ▼
   ┌───────────────────────────┐        Fenster verpasst?
   │   cron.php  (Wächter)  ⏱  │ ─────────────────────────────▶  💥  data/ wird
   │   + Lazy-Check-Fallback   │        keine Bestätigung          restlos gelöscht
   └───────────────────────────┘
```

Der grüne Knopf kann **nur vor Löschung schützen, nie eine auslösen.**

---

## ⚠️ WARNUNG

Dieses Tool **löscht Dateien unwiderruflich**. Wird das Zeitfenster verpasst, ist der
Inhalt des Datenverzeichnisses weg — ohne Rückfrage, ohne Papierkorb.

- Teste die Konfiguration zuerst mit **unwichtigen** Dateien.
- Der Betrieb erfolgt **auf eigene Gefahr**. Es gibt keine Garantie (siehe MIT-Lizenz).
- „Sicheres Löschen" (Überschreiben) ist auf Shared-Hosting/SSD **best-effort** und
  forensisch **nicht garantiert** (siehe unten).

---

## Funktionsprinzip

Das System ist „scharf". Zu jedem eingestellten **Zeitfenster** musst du den grünen Knopf
drücken, um Anwesenheit zu bestätigen (= „Selbstzerstörung abbrechen").

- Knopf **im Fenster gedrückt** → Fenster bestätigt, nichts passiert.
- Knopf **im Fenster NICHT gedrückt** → nach Fensterende löscht ein Wächter (Cronjob) den
  gesamten Inhalt des Datenverzeichnisses.

Der grüne Knopf kann **nur vor Löschung schützen, nie eine auslösen**. Gelöscht wird
ausschließlich zeitgesteuert vom Wächter. Es ist bewusst ein *fail-safe zur Löschung hin*:
Bei Server-Ausfall oder verpasstem Fenster wird gelöscht.

---

## Funktionen

- **Modi:** einmalig · stündlich · täglich · wöchentlich
- **Zeitfenster** frei wählbar („zwischen 00:07 und 00:08 Uhr"), auch über Mitternacht
- **Passwortgeschütztes Backend** (bcrypt, CSRF, Session-Timeout, Rate-Limit pro IP)
- **Öffentliche Recovery-Seite** mit **PIN-Schutz** und großem grünem Knopf
- **Cron-Wächter** + **Lazy-Check** als Fallback bei Seitenaufruf
- **Optionale E-Mail-Benachrichtigung** bei Löschung
- **Restlose Löschung** mit optionalem Überschreiben (Zufallsdaten), strenge Pfad-Absicherung
- **Protokoll** aller Ereignisse im Dashboard
- Reines **PHP** — läuft auf einfachem Shared-Hosting (kein Node.js nötig)

---

## Voraussetzungen

- PHP **7.4+** (getestet gegen 7.4/8.x), Erweiterungen: `json`, `mbstring` (Standard)
- Ein Webserver (Apache mit `.htaccess`-Unterstützung empfohlen)
- Ein **Cronjob** für zeitgenaue Löschung (ohne Cron greift nur der Lazy-Check bei Besuch)

---

## Installation

1. Inhalt des Ordners **`public/`** in das Web-Root der Domain (oder Subdomain) hochladen.
2. Sicherstellen, dass PHP die Verzeichnisse **`storage/`** und **`data/`** beschreiben darf.
3. Backend im Browser aufrufen: `https://deine-domain/admin/`
   → Der **Setup-Wizard** fragt Admin-Passwort, Recovery-PIN und Zeitzone ab.
4. Anmelden, unter **Einstellungen** Modus + Zeitfenster + Datenverzeichnis festlegen.
5. **„Scharf schalten"** klicken.
6. Cronjob einrichten (siehe unten).

> **Wichtig — sofort einrichten:** Solange kein Admin-Passwort gesetzt ist, kann *jeder*
> Besucher von `/admin/` den Setup-Wizard durchlaufen. Führe die Ersteinrichtung deshalb
> **unmittelbar nach dem Upload** durch, bevor die URL bekannt wird.
>
> **Apache erforderlich:** Der Schutz von `storage/`, `lib/` und `data/` beruht auf
> `.htaccess`. Auf Servern ohne Apache bzw. mit `AllowOverride None` sind diese Dateien
> wirkungslos — dann wären `config.json` (Hashes, Cron-Token) und Logs per URL abrufbar.
> Alphahosting (Apache) erfüllt die Voraussetzung.
>
> **Sicherheits-Tipp:** Lege `storage/` idealerweise **außerhalb** des Web-Roots ab, falls
> dein Hosting das erlaubt.

---

## Cronjob einrichten

Der Wächter muss regelmäßig laufen — am besten so oft wie möglich (z. B. minütlich). Er
löscht nur, wenn ein Fenster wirklich abgelaufen und unbestätigt ist; häufige Läufe schaden
also nicht. Das Cron-Intervall sollte **kürzer** sein als der Abstand der Fenster.

**Per URL (wget/curl):**
```
* * * * * wget -qO- "https://deine-domain/cron.php?token=DEIN_TOKEN" >/dev/null 2>&1
```

**Per CLI:**
```
* * * * * php /pfad/zu/public/cron.php >/dev/null 2>&1
```

Die genaue URL inklusive Token zeigt das Backend unter **„Cron & Zugänge"**. Der HTTP-Aufruf
verlangt zwingend das geheime Token; der CLI-Aufruf braucht keins.

---

## Bedienung

- **Recovery:** `https://deine-domain/recover.php` — PIN eingeben, grünen Knopf drücken.
  Die Seite zeigt live, ob ein Fenster offen ist und wann das nächste beginnt.
- **Backend:** `https://deine-domain/admin/` — Status, Einstellungen, Protokoll,
  Scharfschalten/Entschärfen, Passwort-/PIN-Änderung.

---

## Sicherheit

- Admin-Passwort und Recovery-PIN werden als **bcrypt-Hash** gespeichert.
- **CSRF-Schutz** auf allen Formularen, **SameSite=Strict**-Session-Cookies, HttpOnly.
- **Rate-Limit pro IP** für Login und PIN (Brute-Force-Bremse).
- Löschlogik mit strenger **Pfadvalidierung** (kein Path-Traversal, keine Symlink-Ausbrüche,
  System-/App-Ordner sind ausgeschlossen).
- `storage/`, `lib/` und `data/` sind per `.htaccess` gegen Direktzugriff gesperrt.

### PIN & Brute-Force
Die Recovery-PIN hat **6–10 Ziffern** und wird als bcrypt-Hash gespeichert. Login und PIN sind
per **IP-Rate-Limit** (5 Versuche / 15 min) plus kleiner Antwortverzögerung gegen Brute-Force
abgesichert. Ein *globales* PIN-Limit gibt es bewusst **nicht**, da es sich zum Erzwingen einer
Löschung (Aussperren des legitimen Bestätigers) missbrauchen ließe.

### Löschung läuft synchron
Erkennt der **Lazy-Check** (bei einem Seitenaufruf) ein verpasstes Fenster, laufen Löschung +
optionales Überschreiben + E-Mail-Versand **synchron in diesem Request**. Bei sehr großen
Datenmengen kann das den auslösenden Aufruf spürbar verzögern. Für zeitkritische/große
Bestände sollte der **Cronjob** die primäre Auslösung sein (längeres Timeout).

### Grenzen des „sicheren Löschens"
Auf Shared-Hosting und SSDs kann das Überschreiben von Dateien vor dem Löschen **nicht
garantiert** dieselben physischen Speicherzellen treffen (Wear-Leveling, Copy-on-Write,
Snapshots des Hosters). Das Feature ist eine *best-effort*-Maßnahme, kein forensisch
sicheres Löschen.

---

## Projektstruktur

```
public/
├── index.php          Weiterleitung → recover.php
├── recover.php        Öffentlicher grüner Knopf (PIN-geschützt)
├── cron.php           Wächter-Endpoint (Token-geschützt)
├── admin/             Backend (Setup, Login, Dashboard, Einstellungen)
├── lib/               PHP-Klassen (Scheduler, Destroyer, Auth, Watcher …)
├── assets/            CSS & JS
├── storage/           Konfiguration, Zustand, Logs (gesperrt)
└── data/              Das zu überwachende / zu löschende Verzeichnis
```

---

## Lizenz

MIT — siehe [LICENSE](LICENSE).
