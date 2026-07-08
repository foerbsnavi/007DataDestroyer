# 007DataDestroyer — Konzept & Planung

> **Ein „Dead Man's Switch" für ein Web-Verzeichnis.**
> Daten im `data`-Ordner werden automatisch und restlos gelöscht, **wenn** der grüne
> Recovery-Knopf nicht innerhalb eines eingestellten Zeitfensters betätigt wurde.

Status: **UMGESETZT — vollständig implementiert (2026-07-08).** Alle Prüf-Agenten gelaufen,
Befunde eingearbeitet. Dieses Dokument beschreibt das gebaute Tool.
Datum: 2026-07-08

---

## 1. Grundidee (Funktionsprinzip)

Das System ist „scharf" (armed). Zu jedem eingestellten **Zeitfenster** muss der Betreiber
den grünen Knopf drücken, um seine Anwesenheit zu bestätigen (= „Selbstzerstörung abbrechen").

- Knopf **im Fenster gedrückt** → Fenster gilt als bestätigt, nichts passiert.
- Knopf **im Fenster NICHT gedrückt** → nach Fensterende löscht ein Wächter (Cronjob) den
  gesamten Inhalt des `data`-Ordners restlos.

Es ist ein **fail-safe zur Löschung hin**: Im Zweifel (Server-Ausfall, verpasstes Fenster)
wird gelöscht. Das ist gewollt — der sichere Zustand dieses Tools ist „Daten weg".

---

## 2. Einstellungen im Backend (passwortgeschützt)

| Einstellung | Optionen | Beispiel |
|---|---|---|
| **Modus** | einmalig · stündlich · täglich · wöchentlich | täglich |
| **Zeitfenster** | Start- und Endzeit („zwischen X und Y") | 00:07 – 00:08 |
| **Datum** (nur einmalig) | konkretes Datum | 2026-07-20 |
| **Wochentag** (nur wöchentlich) | Mo–So | Montag |
| **Datenverzeichnis** | Standard `data` oder eigener Pfad | `data` |
| **Zeitzone** | Standard Europe/Berlin | Europe/Berlin |
| **Nach Löschung** | scharf bleiben *(Standard)* / deaktivieren | scharf bleiben |
| **Recovery-PIN** | Pflicht — schützt den grünen Knopf | 4–6-stellig |
| **E-Mail-Alarm** | leer / E-Mail-Adresse (optional) | — |

**Fenster-Semantik je Modus**
- **einmalig:** genau ein Fenster `[Datum+Start, Datum+Ende]`. Danach automatisch deaktiviert.
- **stündlich:** jede Stunde ein Fenster (Minuten-Angabe, z. B. `:07–:08` jeder Stunde).
- **täglich:** jeden Tag ein Fenster (Uhrzeit, z. B. `00:07–00:08`).
- **wöchentlich:** an einem Wochentag ein Fenster (Wochentag + Uhrzeit).

---

## 3. Öffentliche Recovery-Seite (`recover.php`)

- Außerhalb des Backends erreichbar (kein Login).
- **Ein großer, grüner, runder Knopf**, mittig, responsive, barrierefrei
  (echtes `<button>`, Tastaturbedienung, ARIA, Kontrast AA).
- Zeigt Live-Status:
  - „Aktuelles Fenster ist **offen** — jetzt bestätigen!" oder
  - „Kein aktives Fenster. Nächstes Fenster: … (Countdown)".
- Klick → schreibt Bestätigung für das aktuell offene Fenster → Erfolgsmeldung.
- Klick außerhalb eines Fensters → Hinweis „nicht nötig / nicht wirksam".
- Optional durch PIN geschützt (siehe Einstellungen).

> Sicherheits-Logik: Der Knopf kann **nur vor Löschung schützen**, nie löschen auslösen.
> Selbst wenn die URL öffentlich bekannt ist, ist die einzige mögliche Wirkung „Daten bleiben".
> Die gefährliche Richtung (Löschung) wird ausschließlich zeitgesteuert vom Wächter ausgelöst.

---

## 4. Wächter / Löschlogik (`cron.php`)

Wird per **Cronjob** regelmäßig aufgerufen (empfohlen jede Minute, mind. so oft wie das
kürzeste Fenster wiederkehrt). Ablauf bei jedem Lauf:

1. Konfiguration & Zustand laden. Wenn nicht scharf → Ende.
2. Alle Fenster ermitteln, die seit dem letzten Lauf **vollständig abgelaufen** sind.
3. Für jedes abgelaufene Fenster prüfen: liegt eine Bestätigung vor?
   - **ja** → nichts tun.
   - **nein** → `data`-Ordner-Inhalt **restlos löschen**, Ereignis protokollieren,
     optional E-Mail, ggf. deaktivieren.
4. `lastCheck`-Zeitstempel aktualisieren.

**Robustheit**
- Cron muss nicht exakt am Fensterende laufen — der Wächter bewertet *abgelaufene* Fenster.
  Bei täglichem Fenster genügt also z. B. ein 5-Minuten-Cron.
- Server-Ausfall über ein Fenster hinweg → Fenster gilt als verpasst → Löschung
  (bewusster fail-safe; wird dokumentiert).
- **Backup ohne Cron:** ein leichter, gedrosselter Check läuft auch bei Aufruf von Admin-/
  Recover-Seite. Verlässliche zeitgenaue Löschung braucht aber den Cron.
- Schutz gegen Missbrauch: `cron.php?token=…` (geheimes Token). Ohne gültiges Token: kein Lauf.
  (Ein öffentlicher Aufruf könnte ohnehin nur die reale Zeitplanung auswerten, nichts fälschen.)

**Löschung „restlos"**
- Rekursiv alle Dateien/Unterordner **im** `data`-Ordner löschen, den Ordner selbst behalten.
- Best-effort sicheres Überschreiben (Zufallsdaten) vor `unlink`, dann löschen.
  *Hinweis:* Auf Shared-Hosting/SSD ist echtes forensisch-sicheres Löschen nicht garantierbar
  — wird im README ehrlich dokumentiert.
- **Pfad-Sicherung:** Zielpfad muss innerhalb des Webroots und exakt der konfigurierte
  `data`-Ordner sein. Kein `..`, kein `/`, kein App-Verzeichnis, kein Symlink-Ausbruch.

---

## 5. Dateistruktur

```
007DataDestroyer/
├── README.md                 # GitHub: Konzept, WARNUNG, Installation, Cron, Sicherheit (DE/EN)
├── LICENSE                   # MIT
├── .gitignore               # storage/*.json, data/* (außer .gitkeep) ausschließen
├── PLAN.md                  # dieses Dokument
├── config.sample.json       # Vorlage
└── public/                  # das, was auf den Webspace kommt (Webroot)
    ├── index.php            # Landing → Weiterleitung/Info
    ├── recover.php          # ÖFFENTLICH — der grüne Knopf
    ├── cron.php             # Wächter-Endpoint (Token-geschützt)
    ├── admin/               # BACKEND — passwortgeschützt
    │   ├── index.php        # Login / Setup-Wizard / Dashboard / Einstellungen
    │   ├── login.php
    │   └── logout.php
    ├── lib/
    │   ├── Config.php        # Laden/Speichern config + state
    │   ├── Auth.php          # Session-Login, CSRF, Rate-Limit
    │   ├── Scheduler.php     # Fensterberechnung (Kernstück)
    │   ├── Destroyer.php     # sichere Löschlogik + Pfadprüfung
    │   ├── Notifier.php      # optionale E-Mail
    │   └── helpers.php
    ├── assets/
    │   ├── css/style.css
    │   └── js/recover.js
    ├── storage/             # Zustand — per .htaccess gesperrt (ideal außerhalb Webroot)
    │   ├── .htaccess        # Deny from all
    │   ├── config.json
    │   ├── state.json
    │   └── destroyer.log
    └── data/                # DAS ZU LÖSCHENDE VERZEICHNIS
        └── .gitkeep
```

---

## 6. Datenmodell

**config.json**
```json
{
  "mode": "daily",
  "window": { "start": "00:07", "end": "00:08" },
  "date": null,
  "weekday": null,
  "dataDir": "data",
  "timezone": "Europe/Berlin",
  "disableAfterDelete": true,
  "recoveryPin": null,
  "notifyEmail": null,
  "cronToken": "…zufällig…",
  "adminPasswordHash": "…bcrypt…",
  "armed": true,
  "createdAt": "2026-07-08T12:00:00+02:00"
}
```

**state.json**
```json
{
  "confirmedWindows": ["2026-07-08T00:07"],
  "lastConfirm": "2026-07-08T00:07:32+02:00",
  "lastCheck": "2026-07-08T00:10:00+02:00",
  "lastDeletion": null,
  "log": [ { "ts": "…", "type": "confirm|check|delete|arm|disarm", "msg": "…" } ]
}
```

Jedes Fenster hat eine **eindeutige ID** aus seiner Startzeit (z. B. `2026-07-08T00:07`).
Bestätigung = ID in `confirmedWindows`. Wächter prüft abgelaufene IDs gegen diese Liste.

---

## 7. Backend-Dashboard (Inhalt)

- **Status:** scharf / inaktiv, aktueller Modus, Datenverzeichnis, Größe/Anzahl Dateien in `data`.
- **Countdown:** nächstes Fenster, verbleibende Zeit, ob gerade offen.
- **Letzte Bestätigung** und **letzte Löschung**.
- **Protokoll** (Historie der Ereignisse).
- **Einstellungen** (Formular, Abschnitt 2) mit deutlicher Warnung + Bestätigungsdialog.
- **Testen:** „Probe-Löschung simulieren" (nur Anzeige, löscht nicht) zur Kontrolle der Fenster.

---

## 8. Sicherheit

- Backend: PHP-Session-Login, Passwort als **bcrypt-Hash** (`password_hash`), CSRF-Token in
  allen Formularen, einfaches Login-Rate-Limit.
- `storage/` und `lib/` per `.htaccess` gegen direkten Web-Zugriff sperren.
- `cron.php`: geheimes Token-Pflicht.
- `recover.php`: kann nur schützen, nie löschen; optionaler PIN.
- Löschlogik: strikte Pfadvalidierung gegen Path-Traversal/Symlink-Ausbruch.
- Erstinstallation: **Setup-Wizard** setzt Admin-Passwort, Zeitzone, Token (keine Default-Passwörter).
- Alle destruktiven Backend-Aktionen mit Klartext-Warnung und Bestätigung.

---

## 9. Rahmenbedingungen (Alphahosting)

- **Nur PHP**, kein Node.js — Umsetzung komplett in PHP/HTML/CSS/JS.
- **Cronjob** nötig für zeitgenaue Löschung → Setup wird im README beschrieben
  (CLI `php cron.php` oder `wget`/`curl` auf `cron.php?token=…`).
- Fenster sollten nicht kürzer als das Cron-Intervall wiederkehren.

---

## 10. GitHub-Veröffentlichung

- Öffentliches Repo (Account `foerbsnavi`), MIT-Lizenz.
- README mit **großer Warnung** (unwiderrufliche Löschung), Screenshots, Installations- und
  Cron-Anleitung, Sicherheitshinweise, Grenzen des „sicheren Löschens".
- `.gitignore` schützt echte `config.json`/`state.json`/`data`-Inhalte vor Veröffentlichung;
  nur `config.sample.json` liegt bei.
- Lokaler Git-Commit nach jeder geprüften Etappe (gemäß Projektregeln).

---

## 11. Getroffene Entscheidungen (2026-07-08)

1. **Recovery-Knopf:** PIN-geschützt (PIN vor dem grünen Knopf). ✔
2. **Nach Löschung:** bleibt scharf (zyklischer Weiterbetrieb), im Backing umstellbar. ✔
3. **E-Mail-Benachrichtigung:** ja, optional (Löschung + optionale Erinnerung). ✔
4. **Design:** 007-/Agenten-Thema — dunkel, „Selbstzerstörung"-Ästhetik, grüner Abbruch-Knopf. ✔

### Noch offen (blockiert Umsetzung nicht, aber wichtig)
- **Cron bei Alphahosting:** verfügbar? Kürzestes Intervall? → bestimmt die sinnvolle
  Mindest-Fensterlänge. Fallback „Lazy-Check" wird ohnehin eingebaut.
```